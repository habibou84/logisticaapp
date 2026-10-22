<?php

namespace App\Services\Orders;

use App\Enums\AssignmentType;
use App\Enums\NotificationEvent;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\WhatsAppTemplate;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\ReturnSlip;
use App\Models\User;
use App\Services\Messaging\Messenger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Bons de retour : les colis d'un marchand à lui rendre partent ensemble avec
 * un livreur ; à la remise, le marchand signe (ou le livreur photographie la
 * remise) et toutes les courses passent « Retourné » d'un coup.
 */
class ReturnSlips
{
    // Statuts d'où un retour peut partir (colis récupéré, pas encore rendu)
    public const RETURNABLE = [
        OrderStatus::PickedUp, OrderStatus::AtHub, OrderStatus::DeliveryAssigned,
        OrderStatus::DeliveryFailed, OrderStatus::Rescheduled, OrderStatus::ReturnAssigned,
    ];

    public function __construct(
        private readonly OrderDispatcher $dispatcher,
        private readonly OrderWorkflow $workflow,
        private readonly OrderJournal $journal,
        private readonly Messenger $messenger,
    ) {}

    /**
     * Colis à rendre, par marchand, pas encore sur un bon.
     *
     * @return Collection<int, Order>
     */
    public function candidates(?int $merchantId = null): Collection
    {
        return Order::query()
            ->where('return_requested', true)
            ->whereNull('return_slip_id')
            ->whereNull('pickup_hub_id')
            ->whereIn('status', OrderStatus::values(self::RETURNABLE))
            ->when($merchantId, fn ($q) => $q->where('merchant_id', $merchantId))
            ->with(['merchant', 'deliveryZone:id,name', 'lastIncidentReason:id,label', 'holder.user', 'returnCourier.user'])
            ->orderBy('merchant_id')->orderBy('id')
            ->get();
    }

    /**
     * @param  list<int>  $orderIds
     */
    public function create(User $actor, Merchant $merchant, Courier $courier, array $orderIds, ?string $notes = null): ReturnSlip
    {
        $orderIds = array_values(array_unique($orderIds));

        $slip = DB::transaction(function () use ($actor, $merchant, $courier, $orderIds, $notes) {
            $orders = Order::query()->whereIn('id', $orderIds)->where('merchant_id', $merchant->id)->lockForUpdate()->with('holder.user')->get();

            if ($orders->count() !== count($orderIds)) {
                throw new BusinessRuleException('Certains colis sont introuvables ou n\'appartiennent pas à ce marchand.', 'order_ids');
            }

            foreach ($orders as $order) {
                $this->assertReturnable($order, $courier);
            }

            $slip = ReturnSlip::create([
                'company_id' => $merchant->company_id,
                'merchant_id' => $merchant->id,
                'courier_id' => $courier->id,
                'reference' => 'TMP-'.Str::random(20),
                'status' => ReturnSlip::OPEN,
                'created_by' => $actor->id,
                'notes' => $notes,
            ]);
            $slip->forceFill(['reference' => sprintf('BR-%s-%05d', now()->format('ymd'), $slip->id)])->save();

            foreach ($orders as $order) {
                if (! $order->return_requested) {
                    $order->forceFill(['return_requested' => true])->save();
                }
                $this->dispatcher->assign($actor, $order, AssignmentType::Return, $courier);
                Order::whereKey($order->id)->update(['return_slip_id' => $slip->id]);
            }

            return $slip;
        });

        DB::afterCommit(fn () => $this->messenger->toMerchant($merchant, NotificationEvent::ReturnSlip, WhatsAppTemplate::ReturnSlip, [
            $merchant->business_name,
            $courier->user?->name ?? 'notre livreur',
            (string) count($orderIds),
            $slip->reference,
            $this->url($slip),
        ]));

        return $slip;
    }

    /**
     * Remise des colis au marchand par le livreur du bon : nom de la personne qui
     * reçoit, signature et/ou photo. Les colis non cochés restent à rendre (hors bon).
     *
     * @param  list<int>|null  $orderIds  colis effectivement remis (tous par défaut)
     */
    public function handOver(User $actor, ReturnSlip $slip, string $receivedBy, ?string $signature, ?UploadedFile $photo, ?array $orderIds = null, ?float $lat = null, ?float $lng = null): ReturnSlip
    {
        if ($slip->status !== ReturnSlip::OPEN) {
            throw new BusinessRuleException('Ce bon de retour n\'est plus en cours.', 'status');
        }

        if ($actor->courier?->id !== $slip->courier_id) {
            throw new BusinessRuleException('Ce bon de retour est confié à un autre livreur.', 'status');
        }

        $signatureData = $this->decodeSignature($signature);

        if ($signatureData === null && $photo === null) {
            throw new BusinessRuleException('Faites signer le marchand ou prenez une photo de la remise.', 'signature');
        }

        DB::transaction(function () use ($actor, $slip, $receivedBy, $signatureData, $photo, $orderIds, $lat, $lng) {
            $orders = $slip->orders()->lockForUpdate()->get();
            $handed = $orderIds === null ? $orders : $orders->whereIn('id', $orderIds);

            if ($handed->isEmpty()) {
                throw new BusinessRuleException('Cochez au moins un colis remis.', 'order_ids');
            }

            $note = "Remis au marchand (bon {$slip->reference}), reçu par {$receivedBy}";

            foreach ($handed as $order) {
                // Déjà rendu individuellement depuis la mission
                if ($order->status === OrderStatus::Returned) {
                    continue;
                }
                if ($order->status === OrderStatus::ReturnAssigned) {
                    $order = $this->workflow->transition($actor, $order, OrderStatus::Returning, ['lat' => $lat, 'lng' => $lng]);
                }
                $this->workflow->transition($actor, $order, OrderStatus::Returned, ['note' => $note, 'lat' => $lat, 'lng' => $lng]);
            }

            // Colis non remis : ils sortent du bon et restent à rendre
            $missing = $orders->diff($handed);
            if ($missing->isNotEmpty()) {
                Order::whereKey($missing->modelKeys())->update(['return_slip_id' => null]);
                foreach ($missing as $order) {
                    $this->journal->record($order, $actor, OrderEventType::Note, [
                        'note' => "Non remis avec le bon {$slip->reference} : reste à rendre au marchand",
                        'visible_to_merchant' => false,
                    ]);
                }
            }

            $dir = "return-slips/{$slip->id}";
            $slip->forceFill([
                'status' => ReturnSlip::HANDED,
                'handed_at' => now(),
                'received_by_name' => $receivedBy,
                'signature_path' => $signatureData !== null ? tap("{$dir}/signature.png", fn ($p) => Storage::disk('local')->put($p, $signatureData)) : null,
                'photo_path' => $photo?->store($dir, 'local'),
                'lat' => $lat,
                'lng' => $lng,
            ])->save();
        });

        $slip->refresh();
        $count = $slip->orders()->count();

        $this->messenger->toMerchant($slip->merchant, NotificationEvent::ReturnSlip, WhatsAppTemplate::ReturnHanded, [
            $slip->merchant->business_name, (string) $count, $slip->reference, $receivedBy, $this->url($slip),
        ]);

        return $slip;
    }

    /**
     * Bon annulé avant la remise : les colis sortent du bon, leur mission de retour reste.
     */
    public function cancel(User $actor, ReturnSlip $slip): ReturnSlip
    {
        if ($slip->status !== ReturnSlip::OPEN) {
            throw new BusinessRuleException('Seul un bon en cours peut être annulé.', 'status');
        }

        DB::transaction(function () use ($slip) {
            $slip->orders()->update(['return_slip_id' => null]);
            $slip->forceFill(['status' => ReturnSlip::CANCELLED])->save();
        });

        return $slip->refresh();
    }

    public function url(ReturnSlip $slip): string
    {
        return $slip->company->url("/bon-de-retour/{$slip->id}");
    }

    private function assertReturnable(Order $order, Courier $courier): void
    {
        if ($order->fromWarehouse()) {
            throw new BusinessRuleException("{$order->tracking_code} : commande d'entrepôt, à remettre en stock.", 'order_ids');
        }

        if (! in_array($order->status, self::RETURNABLE, true)) {
            throw new BusinessRuleException("{$order->tracking_code} : impossible de retourner une course « {$order->status->label()} ».", 'order_ids');
        }

        if ($order->return_slip_id !== null) {
            throw new BusinessRuleException("{$order->tracking_code} est déjà sur un bon de retour.", 'order_ids');
        }

        // Le colis doit être au dépôt ou déjà chez le livreur du bon
        if ($order->held_by_courier_id !== null && $order->held_by_courier_id !== $courier->id) {
            $holder = $order->holder?->user?->name ?? 'un autre livreur';
            throw new BusinessRuleException("{$order->tracking_code} est encore chez {$holder} : recevez-le d'abord au dépôt ou confiez-lui le bon.", 'order_ids');
        }
    }

    /**
     * Signature dessinée à l'écran (image PNG en data URL).
     */
    private function decodeSignature(?string $signature): ?string
    {
        if (blank($signature)) {
            return null;
        }

        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $signature, $m)) {
            throw new BusinessRuleException('Signature illisible.', 'signature');
        }

        $data = base64_decode($m[1], true);

        if ($data === false || strlen($data) > 512 * 1024 || ! str_starts_with($data, "\x89PNG")) {
            throw new BusinessRuleException('Signature illisible.', 'signature');
        }

        return $data;
    }
}
