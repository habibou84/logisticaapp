<?php

namespace App\Services\Couriers;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Http\Requests\V1\TransitionOrderRequest;
use App\Models\OfflineAction;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderAlert;
use App\Services\Orders\OrderJournal;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Actions d'un livreur faites sans réseau, envoyées une à une au retour de la connexion :
 *  - chaque action n'est appliquée qu'une fois (identifiant tiré par le téléphone) ;
 *  - elle est datée de son heure réelle, si elle est vraisemblable (3 jours au plus) ;
 *  - une livraison avec un code client faux est gardée mais signalée (code à vérifier) ;
 *  - une action devenue impossible (course annulée ou réattribuée entre-temps) est refusée :
 *    le livreur est prévenu et le refus est noté sur la course pour le dispatch.
 */
class OfflineSync
{
    public const KINDS = ['status', 'note'];

    // Heure du téléphone acceptée : jusqu'à 3 jours en arrière, 5 minutes en avance (horloge décalée)
    private const MAX_AGE_HOURS = 72;

    private const MAX_AHEAD_MINUTES = 5;

    public function __construct(
        private readonly OrderWorkflow $workflow,
        private readonly OrderService $orders,
        private readonly OrderJournal $journal,
    ) {}

    /**
     * @param  array{id: string, kind: string, order_id: int, occurred_at?: string|null, payload?: array<string, mixed>}  $action
     * @return array{id: string, result: string, message: ?string, order_status: ?string}
     */
    public function apply(User $courier, array $action): array
    {
        $existing = $this->find($courier, $action['id']);
        if ($existing) {
            return $this->result($existing);
        }

        $order = Order::find($action['order_id']);
        $occurredAt = $this->occurredAt($action['occurred_at'] ?? null);
        $payload = $action['payload'] ?? [];

        if ($order === null) {
            return $this->store($courier, $action, null, OfflineAction::REJECTED, 'Course introuvable.', $occurredAt);
        }

        try {
            match ($action['kind']) {
                'status' => $this->transition($courier, $order, $payload, $occurredAt),
                'note' => $this->note($courier, $order, $payload),
            };
        } catch (BusinessRuleException|AuthorizationException|ValidationException $e) {
            $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
            $message = $message ?: 'Action refusée.';
            $this->noteRejection($courier, $order, $message);

            return $this->store($courier, $action, $order, OfflineAction::REJECTED, $message, $occurredAt);
        }

        return $this->store($courier, $action, $order->fresh(), OfflineAction::OK, null, $occurredAt);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function transition(User $courier, Order $order, array $payload, Carbon $occurredAt): void
    {
        $data = Validator::make($payload, (new TransitionOrderRequest)->rules())->validate();

        $order = $this->workflow->transition($courier, $order, OrderStatus::from($data['status']), [
            ...$data,
            'offline' => true,
            'occurred_at' => $occurredAt,
        ]);

        if ($order->status === OrderStatus::Delivered && $order->delivery_code_unverified_at) {
            $this->alertCodeToVerify($order, $courier);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function note(User $courier, Order $order, array $payload): void
    {
        $data = Validator::make($payload, [
            'note' => ['required', 'string', 'max:2000'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ])->validate();

        if (! $courier->can('addNote', $order)) {
            throw new AuthorizationException('Cette course ne vous est plus assignée.');
        }

        $this->orders->addNote($courier, $order, $data['note'], true, $data['lat'] ?? null, $data['lng'] ?? null);
    }

    private function occurredAt(?string $value): Carbon
    {
        try {
            $at = $value ? Carbon::parse($value) : null;
        } catch (\Throwable) {
            $at = null;
        }

        if ($at === null || $at->isAfter(now()->addMinutes(self::MAX_AHEAD_MINUTES)) || $at->isBefore(now()->subHours(self::MAX_AGE_HOURS))) {
            return now();
        }

        return $at->min(now());
    }

    /**
     * Refus noté sur la course (dispatch), sans prévenir le marchand.
     */
    private function noteRejection(User $courier, Order $order, string $message): void
    {
        $this->journal->record($order, $courier, OrderEventType::Note, [
            'note' => "Action faite hors connexion refusée à l'envoi : {$message}",
            'visible_to_merchant' => false,
            'meta' => ['offline_rejected' => true],
        ]);
    }

    private function alertCodeToVerify(Order $order, User $courier): void
    {
        $users = User::forCompany($order->company_id)->where('status', 'active')
            ->role([Role::Admin->value, Role::Dispatcher->value])->get();

        if ($users->isNotEmpty()) {
            Notification::send($users, new OrderAlert(
                $order,
                'delivery_code_unverified',
                "Code client à vérifier · {$order->tracking_code}",
                "{$courier->name} a livré hors connexion avec un code client incorrect. Vérifiez la livraison auprès du destinataire.",
            ));
        }
    }

    private function find(User $courier, string $clientId): ?OfflineAction
    {
        return OfflineAction::forCompany($courier->company_id)
            ->where('user_id', $courier->id)->where('client_id', $clientId)->first();
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{id: string, result: string, message: ?string, order_status: ?string}
     */
    private function store(User $courier, array $action, ?Order $order, string $status, ?string $message, Carbon $occurredAt): array
    {
        try {
            $record = OfflineAction::create([
                'company_id' => $courier->company_id,
                'user_id' => $courier->id,
                'client_id' => $action['id'],
                'order_id' => $order?->id,
                'kind' => $action['kind'],
                'status' => $status,
                'message' => $message,
                'occurred_at' => $occurredAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Même action envoyée deux fois en même temps : la première fait foi
            $record = $this->find($courier, $action['id']);
        }

        $record->setRelation('order', $order);

        return $this->result($record);
    }

    /**
     * @return array{id: string, result: string, message: ?string, order_status: ?string}
     */
    private function result(OfflineAction $record): array
    {
        return [
            'id' => $record->client_id,
            'result' => $record->status,
            'message' => $record->message,
            'order_status' => $record->order?->status?->value,
        ];
    }
}
