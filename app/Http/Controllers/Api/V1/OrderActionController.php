<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AssignmentType;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\TransitionOrderRequest;
use App\Http\Resources\V1\OrderResource;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Services\Orders\OrderDecisions;
use App\Services\Orders\OrderDispatcher;
use App\Services\Orders\OrderJournal;
use App\Services\Orders\OrderLosses;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderActionController extends Controller
{
    public function __construct(
        private readonly OrderWorkflow $workflow,
        private readonly OrderDispatcher $dispatcher,
        private readonly OrderService $orders,
        private readonly OrderJournal $journal,
        private readonly OrderDecisions $decisions,
        private readonly OrderLosses $losses,
    ) {}

    public function transition(TransitionOrderRequest $request, Order $order): OrderResource
    {
        $data = $request->validated();

        $order = $this->workflow->transition(
            $request->user(),
            $order,
            OrderStatus::from($data['status']),
            $data,
        );

        return $this->detail($request, $order);
    }

    /**
     * Suite donnée à un colis non livré : relivrer, retourner au marchand, remettre en stock.
     */
    public function decide(Request $request, Order $order): OrderResource
    {
        Gate::authorize('dispatch', Order::class);

        $data = $request->validate([
            'decision' => ['required', Rule::in([OrderDecisions::REDELIVER, OrderDecisions::RETURN, OrderDecisions::RESTOCK])],
            'date' => ['required_if:decision,redeliver', 'nullable', 'date', 'after_or_equal:today'],
            'courier_id' => ['nullable', 'integer', Rule::exists('couriers', 'id')->where('company_id', $request->user()->company_id)],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['decision' => 'décision', 'date' => 'date de livraison', 'courier_id' => 'livreur']);

        return $this->detail($request, $this->decisions->decide($request->user(), $order, $data));
    }

    /**
     * Colis perdu : indemnité au marchand, retenue éventuelle sur la paie du livreur.
     */
    public function declareLost(Request $request, Order $order): OrderResource
    {
        Gate::authorize('dispatch', Order::class);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'compensation' => ['required', 'integer', 'min:0', 'max:100000000'],
            'courier_deduction' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'responsible_courier_id' => ['nullable', 'integer', Rule::exists('couriers', 'id')->where('company_id', $request->user()->company_id)],
        ], [], ['reason' => 'circonstances', 'compensation' => 'indemnité', 'courier_deduction' => 'retenue', 'responsible_courier_id' => 'livreur responsable']);

        return $this->detail($request, $this->losses->declare($request->user(), $order, $data));
    }

    public function assign(Request $request, Order $order): OrderResource
    {
        Gate::authorize('dispatch', Order::class);

        $data = $this->validateAssignment($request);

        $this->dispatcher->assign(
            $request->user(),
            $order,
            AssignmentType::from($data['type']),
            Courier::findOrFail($data['courier_id']),
        );

        return $this->detail($request, $order);
    }

    /**
     * Retire le livreur d'une mission : la course attend un autre livreur.
     */
    public function unassign(Request $request, Order $order): OrderResource
    {
        Gate::authorize('dispatch', Order::class);

        $data = $request->validate([
            'type' => ['required', Rule::enum(AssignmentType::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [], ['type' => 'type de mission', 'reason' => 'motif']);

        $this->dispatcher->unassign($request->user(), $order, AssignmentType::from($data['type']), $data['reason'] ?? null);

        return $this->detail($request, $order);
    }

    /**
     * Retire le livreur de plusieurs courses (type facultatif : étape en cours de chaque course).
     */
    public function bulkUnassign(Request $request): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);

        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['integer', 'distinct'],
            'type' => ['nullable', Rule::enum(AssignmentType::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [], ['type' => 'type de mission', 'reason' => 'motif']);

        $result = $this->dispatcher->unassignMany(
            $request->user(),
            $data['order_ids'],
            isset($data['type']) ? AssignmentType::from($data['type']) : null,
            $data['reason'] ?? null,
        );

        return response()->json(['data' => $result]);
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);

        $data = $this->validateAssignment($request, [
            'order_ids' => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['integer', 'distinct'],
        ]);

        $result = $this->dispatcher->assignMany(
            $request->user(),
            $data['order_ids'],
            AssignmentType::from($data['type']),
            Courier::findOrFail($data['courier_id']),
        );

        return response()->json(['data' => $result]);
    }

    /**
     * Validation groupée des courses en attente (comme l'assignation groupée) :
     * chaque course est validée seule ; les refus sont renvoyés course par course.
     */
    public function bulkConfirm(Request $request): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);

        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['integer', 'distinct'],
        ]);

        $result = ['confirmed' => [], 'errors' => []];

        foreach (Order::whereIn('id', $data['order_ids'])->orderBy('id')->get() as $order) {
            if ($order->status !== OrderStatus::Pending) {
                $result['errors'][$order->id] = "Déjà « {$order->statusLabel()} » : pas à valider.";

                continue;
            }

            try {
                $this->workflow->transition($request->user(), $order, OrderStatus::Confirmed);
                $result['confirmed'][] = $order->id;
            } catch (BusinessRuleException|AuthorizationException $e) {
                $result['errors'][$order->id] = $e->getMessage();
            }
        }

        foreach (array_diff($data['order_ids'], $result['confirmed'], array_keys($result['errors'])) as $missing) {
            $result['errors'][$missing] = 'Course introuvable.';
        }

        return response()->json(['data' => $result]);
    }

    public function note(Request $request, Order $order): JsonResponse
    {
        Gate::authorize('addNote', $order);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'visible_to_merchant' => ['sometimes', 'boolean'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $this->orders->addNote(
            $request->user(),
            $order,
            $data['note'],
            $data['visible_to_merchant'] ?? true,
            $data['lat'] ?? null,
            $data['lng'] ?? null,
        );

        return response()->json(['message' => 'Note ajoutée.'], 201);
    }

    /**
     * Livraison faite hors connexion avec un code client incorrect : vérifiée auprès du destinataire.
     */
    public function verifyDeliveryCode(Request $request, Order $order): OrderResource
    {
        Gate::authorize('dispatch', Order::class);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        if ($order->delivery_code_unverified_at === null) {
            throw new BusinessRuleException('Cette livraison n\'est pas à vérifier.', 'status');
        }

        DB::transaction(function () use ($request, $order, $data) {
            $order->forceFill(['delivery_code_unverified_at' => null])->save();
            $this->journal->record($order, $request->user(), OrderEventType::Note, [
                'note' => 'Livraison vérifiée auprès du destinataire (code saisi hors connexion incorrect)'.(filled($data['note'] ?? null) ? ' : '.$data['note'] : '.'),
                'visible_to_merchant' => false,
            ]);
        });

        return $this->detail($request, $order);
    }

    public function requestReturn(Request $request, Order $order): OrderResource
    {
        Gate::authorize('requestReturn', $order);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $this->orders->requestReturn($request->user(), $order, $data['note'] ?? null);

        return $this->detail($request, $order);
    }

    /**
     * Photo de preuve (ramassage, livraison, incident) prise par le livreur ou le personnel.
     */
    public function storeAttachment(Request $request, Order $order): JsonResponse
    {
        Gate::authorize('addAttachment', $order);

        $data = $request->validate([
            'file' => ['required', 'image', 'max:5120'],
            'type' => ['required', Rule::in(['photo_pickup', 'photo_delivery', 'photo_incident', 'document'])],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['file' => 'photo']);

        $attachment = DB::transaction(function () use ($request, $order, $data) {
            $event = $this->journal->record($order, $request->user(), OrderEventType::ProofAdded, [
                'note' => $data['note'] ?? null,
                'meta' => ['attachment_type' => $data['type']],
            ]);

            $path = $request->file('file')->store("orders/{$order->id}", 'local');

            return OrderAttachment::create([
                'order_id' => $order->id,
                'event_id' => $event->id,
                'type' => $data['type'],
                'disk' => 'local',
                'path' => $path,
                'mime_type' => $request->file('file')->getMimeType(),
                'uploaded_by' => $request->user()->id,
            ]);
        });

        return response()->json(['data' => [
            'id' => $attachment->id,
            'type' => $attachment->type,
            'url' => route('v1.orders.attachments.show', [$order->id, $attachment->id], false),
        ]], 201);
    }

    public function showAttachment(Order $order, OrderAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $order);
        abort_unless($attachment->order_id === $order->id, 404);

        return Storage::disk($attachment->disk)->response($attachment->path);
    }

    private function validateAssignment(Request $request, array $extra = []): array
    {
        return $request->validate([
            ...$extra,
            'type' => ['required', Rule::enum(AssignmentType::class)],
            'courier_id' => ['required', 'integer', Rule::exists('couriers', 'id')->where('company_id', $request->user()->company_id)],
        ], [], ['courier_id' => 'livreur', 'type' => 'type de mission']);
    }

    private function detail(Request $request, Order $order): OrderResource
    {
        return OrderResource::make($order->fresh()->load(OrderController::loadDetailRelations($request)));
    }
}
