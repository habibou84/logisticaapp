<?php

namespace App\Services\Orders;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\ReturnHandover;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Notifications\ReturnHandoverToReview;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Remise au client des colis qu'un livreur lui rapporte, avec la preuve que le client les a reçus :
 *  - le livreur affiche un QR code ; le client le scanne, WhatsApp s'ouvre sur le numéro de l'entreprise avec le
 *    message « RETOUR R-4821 : j'ai bien reçu les 2 colis » déjà écrit ; il l'envoie depuis son numéro enregistré ;
 *  - sans scan, il écrit « retour » et touche « Oui, tout reçu » ; ou il confirme dans son application ;
 *  - sans téléphone ni réseau : remise de secours (motif, nom, signature ou photo), l'agence vérifie.
 * C'est toujours le client qui écrit en premier : la réponse de l'entreprise reste gratuite chez Meta.
 */
class ReturnHandovers
{
    public function __construct(private readonly OrderWorkflow $workflow) {}

    /**
     * Colis que ce livreur rapporte à ce client (hors bons de retour, remis avec leur propre écran).
     *
     * @return Collection<int, Order>
     */
    public function returnable(Courier $courier, int $merchantId): Collection
    {
        return Order::query()
            ->where('company_id', $courier->company_id)
            ->where('merchant_id', $merchantId)
            ->whereNull('return_slip_id')
            ->whereIn('status', OrderStatus::values([OrderStatus::ReturnAssigned, OrderStatus::Returning]))
            ->whereHas('assignments', fn ($q) => $q->active()->where('type', AssignmentType::Return->value)->where('courier_id', $courier->id))
            ->orderBy('id')
            ->get();
    }

    /**
     * Le livreur est chez le client : remise en attente de confirmation (la même tant qu'elle est en cours).
     */
    public function start(User $actor, Merchant $merchant): ReturnHandover
    {
        $courier = $actor->courier ?? throw new BusinessRuleException('Réservé aux livreurs.', 'courier');
        $orders = $this->returnable($courier, $merchant->id);

        if ($orders->isEmpty()) {
            throw new BusinessRuleException('Vous n\'avez aucun colis à rendre à ce client.', 'merchant_id');
        }

        return DB::transaction(function () use ($courier, $merchant, $orders) {
            $handover = ReturnHandover::query()->where('company_id', $courier->company_id)
                ->where('courier_id', $courier->id)->where('merchant_id', $merchant->id)
                ->where('status', ReturnHandover::PENDING)->lockForUpdate()->latest('id')->first();

            $handover ??= new ReturnHandover([
                'company_id' => $courier->company_id,
                'merchant_id' => $merchant->id,
                'courier_id' => $courier->id,
                'status' => ReturnHandover::PENDING,
            ]);

            $handover->order_ids = $orders->modelKeys();
            if (! $handover->exists || $handover->isExpired()) {
                $this->renewReference($handover);
            }
            $handover->save();

            return $handover;
        });
    }

    /** QR code expiré : nouvelle référence, valable de nouveau */
    public function renew(User $actor, ReturnHandover $handover): ReturnHandover
    {
        $this->assertCourier($actor, $handover);
        if ($handover->status !== ReturnHandover::PENDING) {
            throw new BusinessRuleException('Cette remise n\'est plus en attente.', 'status');
        }
        $this->renewReference($handover);
        $handover->save();

        return $handover;
    }

    public function cancel(User $actor, ReturnHandover $handover): ReturnHandover
    {
        $this->assertCourier($actor, $handover);
        if ($handover->status === ReturnHandover::PENDING) {
            $handover->forceFill(['status' => ReturnHandover::CANCELLED])->save();
        }

        return $handover;
    }

    /**
     * Le client confirme (message WhatsApp, bouton, application) : les colis passent « Retourné ». Une remise de
     * secours confirmée après coup n'a plus besoin de vérification.
     */
    public function confirm(ReturnHandover $handover, string $via, ?string $phone = null): ReturnHandover
    {
        DB::transaction(function () use ($handover, $via, $phone) {
            $handover = ReturnHandover::query()->lockForUpdate()->findOrFail($handover->id);

            if ($handover->status === ReturnHandover::PENDING || $handover->status === ReturnHandover::DISPUTED) {
                $this->markReturned($handover, $this->courierUser($handover), $this->confirmNote($via, $handover));
                $handover->handed_at ??= now();
            } elseif ($handover->status !== ReturnHandover::UNCONFIRMED) {
                return;
            }

            $handover->forceFill([
                'status' => ReturnHandover::CONFIRMED,
                'confirmed_at' => now(),
                'confirmed_via' => $via,
                'confirmed_phone' => $phone,
            ])->save();
        });

        return $handover->refresh();
    }

    /**
     * « Il en manque » : les colis restent chez le livreur, l'agence tranche. Après une remise de secours, la remise
     * devient contestée.
     */
    public function dispute(ReturnHandover $handover, ?string $phone = null): ReturnHandover
    {
        if (! in_array($handover->status, [ReturnHandover::PENDING, ReturnHandover::UNCONFIRMED], true)) {
            return $handover;
        }

        $handover->forceFill(['status' => ReturnHandover::DISPUTED, 'disputed_at' => now(), 'confirmed_phone' => $phone])->save();
        $this->alertAgency($handover);

        return $handover;
    }

    /**
     * Remise sans confirmation du client : motif, nom de la personne, signature ou photo. Les colis passent
     * « Retourné », l'agence vérifie.
     */
    public function fallback(User $actor, ReturnHandover $handover, string $reason, string $receivedBy, ?string $signature, ?UploadedFile $photo, ?float $lat = null, ?float $lng = null): ReturnHandover
    {
        $this->assertCourier($actor, $handover);

        if ($handover->status !== ReturnHandover::PENDING) {
            throw new BusinessRuleException('Cette remise n\'est plus en attente.', 'status');
        }
        if (! array_key_exists($reason, ReturnHandover::FALLBACK_REASONS)) {
            throw new BusinessRuleException('Choisissez pourquoi le client ne peut pas confirmer.', 'reason');
        }

        $signatureData = ReturnSlips::decodeSignature($signature);
        if ($signatureData === null && $photo === null) {
            throw new BusinessRuleException('Faites signer la personne ou prenez une photo de la remise.', 'signature');
        }

        DB::transaction(function () use ($actor, $handover, $reason, $receivedBy, $signatureData, $photo, $lat, $lng) {
            $locked = ReturnHandover::query()->lockForUpdate()->findOrFail($handover->id);
            if ($locked->status !== ReturnHandover::PENDING) {
                throw new BusinessRuleException('Cette remise n\'est plus en attente.', 'status');
            }

            $this->markReturned($handover, $actor, "Remis à {$receivedBy} sans confirmation du client (".ReturnHandover::FALLBACK_REASONS[$reason].')', $lat, $lng);

            $dir = "return-handovers/{$handover->id}";
            $handover->forceFill([
                'status' => ReturnHandover::UNCONFIRMED,
                'fallback_reason' => $reason,
                'received_by_name' => $receivedBy,
                'signature_path' => $signatureData !== null ? tap("{$dir}/signature.png", fn ($p) => Storage::disk('local')->put($p, $signatureData)) : null,
                'photo_path' => $photo?->store($dir, 'local'),
                'lat' => $lat,
                'lng' => $lng,
                'handed_at' => now(),
            ])->save();
        });

        $this->alertAgency($handover);

        return $handover->refresh();
    }

    /**
     * Décision de l'agence : returned (le client confirme, colis rendus), kept (colis toujours chez le livreur :
     * la remise est annulée, les colis restent à rendre), verified (remise de secours vérifiée).
     */
    public function resolve(User $actor, ReturnHandover $handover, string $decision): ReturnHandover
    {
        if (! $handover->needsReview()) {
            throw new BusinessRuleException('Cette remise n\'attend pas de décision.', 'status');
        }

        DB::transaction(function () use ($actor, $handover, $decision) {
            $unconfirmed = $handover->status === ReturnHandover::UNCONFIRMED;

            match (true) {
                $decision === 'returned' && ! $unconfirmed => $this->markReturned($handover, $actor, "Rendu au client, confirmé par l'agence (remise {$handover->reference})"),
                $decision === 'kept' && ! $unconfirmed, $decision === 'verified' && $unconfirmed, $decision === 'returned' => null,
                default => throw new BusinessRuleException('Décision impossible pour cette remise.', 'decision'),
            };

            $handover->forceFill([
                'status' => $decision === 'kept' ? ReturnHandover::CANCELLED : ReturnHandover::RESOLVED,
                'resolution' => $decision,
                'resolved_at' => now(),
                'resolved_by' => $actor->id,
                'handed_at' => $decision === 'kept' ? $handover->handed_at : ($handover->handed_at ?? now()),
            ])->save();
        });

        return $handover->refresh();
    }

    /** Remise en attente (ou de secours, encore confirmable) dont le client cite la référence */
    public function findByReference(Company $company, Merchant $merchant, string $reference): ?ReturnHandover
    {
        return ReturnHandover::query()->where('company_id', $company->id)->where('merchant_id', $merchant->id)
            ->where('reference', $reference)
            ->whereIn('status', [ReturnHandover::PENDING, ReturnHandover::UNCONFIRMED])
            ->where('updated_at', '>=', now()->subDays(2))
            ->latest('id')->first();
    }

    /**
     * Remises que ce client peut confirmer : en attente (livreur chez lui) ou de secours récentes.
     *
     * @return Collection<int, ReturnHandover>
     */
    public function awaitingMerchant(int $companyId, int $merchantId): Collection
    {
        return ReturnHandover::query()->where('company_id', $companyId)->where('merchant_id', $merchantId)
            ->where(fn ($q) => $q->where(fn ($p) => $p->where('status', ReturnHandover::PENDING)->where('updated_at', '>=', now()->subHours(2)))
                ->orWhere(fn ($u) => $u->where('status', ReturnHandover::UNCONFIRMED)->where('handed_at', '>=', now()->subDays(2))))
            ->with('courier.user')
            ->latest('id')->get();
    }

    /** Lien WhatsApp du QR code : numéro de l'entreprise, message déjà écrit (null sans numéro WhatsApp relié) */
    public function whatsappLink(ReturnHandover $handover): ?string
    {
        $account = WhatsAppAccount::forCompanyId($handover->company_id);
        $digits = preg_replace('/\D/', '', (string) $account?->display_phone);

        if ($account === null || $account->status !== 'connected' || $digits === '') {
            return null;
        }

        return "https://wa.me/{$digits}?text=".rawurlencode($handover->message());
    }

    private function markReturned(ReturnHandover $handover, User $actor, string $note, ?float $lat = null, ?float $lng = null): void
    {
        foreach ($handover->orders() as $order) {
            if (! in_array($order->status, [OrderStatus::ReturnAssigned, OrderStatus::Returning], true)) {
                continue;
            }
            $context = ['note' => $note, 'lat' => $lat, 'lng' => $lng, 'return_proof' => $handover];
            if ($order->status === OrderStatus::ReturnAssigned) {
                $order = $this->workflow->transition($actor, $order, OrderStatus::Returning, $context);
            }
            $this->workflow->transition($actor, $order, OrderStatus::Returned, $context);
        }
    }

    private function confirmNote(string $via, ReturnHandover $handover): string
    {
        return match ($via) {
            'app' => "Rendu au client, confirmé dans son application (remise {$handover->reference})",
            default => "Rendu au client, confirmé par WhatsApp (remise {$handover->reference})",
        };
    }

    /** Le livreur de la remise : c'est lui qui rend les colis (étape « Retourné » à son nom) */
    private function courierUser(ReturnHandover $handover): User
    {
        return $handover->courier?->user ?? throw new BusinessRuleException('Livreur introuvable.', 'courier');
    }

    private function assertCourier(User $actor, ReturnHandover $handover): void
    {
        if ($actor->courier?->id !== $handover->courier_id) {
            throw new BusinessRuleException('Cette remise est faite par un autre livreur.', 'courier');
        }
    }

    private function renewReference(ReturnHandover $handover): void
    {
        // Quatre chiffres, uniques parmi les remises encore confirmables de l'entreprise
        do {
            $reference = 'R-'.random_int(1000, 9999);
        } while (ReturnHandover::query()->where('company_id', $handover->company_id)->where('reference', $reference)
            ->whereIn('status', [ReturnHandover::PENDING, ReturnHandover::UNCONFIRMED])
            ->when($handover->exists, fn ($q) => $q->whereKeyNot($handover->id))->exists());

        $handover->reference = $reference;
        $handover->expires_at = now()->addMinutes(ReturnHandover::VALID_MINUTES);
    }

    /** Remise de secours ou contestée : cloche des personnes qui gèrent les retours */
    private function alertAgency(ReturnHandover $handover): void
    {
        $staff = User::forCompany($handover->company_id)->where('status', 'active')->whereNull('merchant_id')
            ->permission('orders.dispatch')->get();

        if ($staff->isNotEmpty()) {
            DB::afterCommit(fn () => Notification::send($staff, new ReturnHandoverToReview($handover->fresh(['merchant', 'courier.user']))));
        }
    }
}
