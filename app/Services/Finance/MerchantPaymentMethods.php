<?php

namespace App\Services\Finance;

use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\WhatsAppTemplate;
use App\Exceptions\BusinessRuleException;
use App\Models\Merchant;
use App\Models\MerchantPaymentMethod;
use App\Models\MerchantPaymentMethodEvent;
use App\Models\User;
use App\Notifications\MerchantPaymentMethodChanged;
use App\Services\Messaging\Messenger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Moyens de paiement des marchands. Ce que le client ajoute ou change reste « à vérifier » jusqu'à la
 * validation de l'agence ; ce que l'agence saisit elle-même est vérifié d'office. Chaque ajout, changement
 * ou retrait est noté dans l'historique, signalé au client sur WhatsApp et, venant du client, aux
 * responsables de la caisse.
 */
class MerchantPaymentMethods
{
    /** Champs dont le changement remet le moyen « à vérifier » */
    private const SENSITIVE = ['type', 'number', 'holder_name', 'bank_name', 'payment_link'];

    public function __construct(private Messenger $messenger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function add(User $actor, Merchant $merchant, array $data, ?UploadedFile $qr = null): MerchantPaymentMethod
    {
        $byMerchant = $actor->merchant_id !== null;

        $method = DB::transaction(function () use ($actor, $merchant, $data, $byMerchant) {
            $method = $merchant->paymentMethods()->create([
                'company_id' => $merchant->company_id,
                ...$this->fields($data),
                ...$this->status($actor, $byMerchant),
                'is_preferred' => false,
            ]);
            if (($data['is_preferred'] ?? false) || ! $merchant->paymentMethods()->whereKeyNot($method->id)->where('is_preferred', true)->exists()) {
                $this->makePreferred($method);
            }
            $this->log($actor, $method, 'added', 'Ajout : '.$method->summary());

            return $method;
        });

        if ($qr !== null) {
            $this->storeQr($method, $qr);
        }
        $this->announce($merchant, 'added', $method->summary(), $byMerchant);

        return $method->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, MerchantPaymentMethod $method, array $data, ?UploadedFile $qr = null, bool $removeQr = false): MerchantPaymentMethod
    {
        $byMerchant = $actor->merchant_id !== null;
        $method->fill($this->fields($data));
        $changed = $method->isDirty(self::SENSITIVE) || $qr !== null || ($removeQr && $method->qr_path);

        if (! $changed && ! ($data['is_preferred'] ?? false)) {
            return $method;
        }

        DB::transaction(function () use ($actor, $method, $data, $byMerchant, $changed) {
            if ($changed) {
                $method->fill($this->status($actor, $byMerchant));
            }
            $method->save();
            if ($data['is_preferred'] ?? false) {
                $this->makePreferred($method);
            }
            if ($changed) {
                $this->log($actor, $method, 'updated', 'Changement : '.$method->summary());
            }
        });

        if ($removeQr || $qr !== null) {
            $this->deleteQr($method);
        }
        if ($qr !== null) {
            $this->storeQr($method, $qr);
        }
        if ($changed) {
            $this->announce($method->merchant, 'updated', $method->summary(), $byMerchant);
        }

        return $method->refresh();
    }

    public function remove(User $actor, MerchantPaymentMethod $method): void
    {
        $merchant = $method->merchant;
        $summary = $method->summary();

        DB::transaction(function () use ($actor, $method, $merchant, $summary) {
            $this->log($actor, $method, 'removed', 'Retrait : '.$summary);
            $wasPreferred = $method->is_preferred;
            $this->deleteQr($method);
            $method->delete();
            // Le moyen préféré retiré : le suivant prend sa place, un moyen vérifié d'abord
            if ($wasPreferred) {
                $next = $merchant->paymentMethods()->reorder()->orderByRaw("CASE WHEN status = 'verified' THEN 0 ELSE 1 END")->orderBy('id')->first();
                $next?->update(['is_preferred' => true]);
            }
        });

        $this->announce($merchant, 'removed', $summary, $actor->merchant_id !== null);
    }

    public function prefer(User $actor, MerchantPaymentMethod $method): MerchantPaymentMethod
    {
        if (! $method->is_preferred) {
            DB::transaction(function () use ($actor, $method) {
                $this->makePreferred($method);
                $this->log($actor, $method, 'preferred', 'En premier : '.$method->summary());
            });
        }

        return $method->refresh();
    }

    public function verify(User $actor, MerchantPaymentMethod $method): MerchantPaymentMethod
    {
        // Double contrôle : celui qui a saisi le moyen de paiement (personnel) ne le valide pas lui-même
        $enteredBy = MerchantPaymentMethodEvent::where('payment_method_id', $method->id)->whereIn('action', ['added', 'updated'])
            ->where('by_merchant', false)->latest('id')->value('user_id');
        if ($enteredBy !== null && $enteredBy === $actor->id) {
            throw new BusinessRuleException('Vous avez saisi ce moyen de paiement : une autre personne de l\'équipe doit le vérifier.');
        }
        $method->update(['status' => MerchantPaymentMethod::VERIFIED, 'verified_by' => $actor->id, 'verified_at' => now(), 'rejected_reason' => null]);
        $this->log($actor, $method, 'verified', 'Validé : '.$method->summary());

        return $method;
    }

    public function reject(User $actor, MerchantPaymentMethod $method, ?string $reason = null): MerchantPaymentMethod
    {
        $method->update(['status' => MerchantPaymentMethod::REJECTED, 'verified_by' => $actor->id, 'verified_at' => now(), 'rejected_reason' => $reason]);
        $this->log($actor, $method, 'rejected', 'Refusé : '.$method->summary().($reason ? " ({$reason})" : ''));

        return $method;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        $type = PaymentMethod::from($data['type']);

        return [
            'type' => $type,
            'number' => $data['number'],
            'holder_name' => $data['holder_name'] ?? null,
            'bank_name' => $type === PaymentMethod::Bank ? ($data['bank_name'] ?? null) : null,
            'payment_link' => $data['payment_link'] ?? null,
        ];
    }

    /**
     * Le marchand ou la caisse : à vérifier (par une autre personne pour la caisse) ; un administrateur : vérifié
     * d'office. Un caissier ne peut pas ajouter son propre numéro et le payer aussitôt.
     *
     * @return array<string, mixed>
     */
    private function status(User $actor, bool $byMerchant): array
    {
        return $byMerchant || ! $actor->hasRole(Role::Admin->value)
            ? ['status' => MerchantPaymentMethod::PENDING, 'verified_by' => null, 'verified_at' => null, 'rejected_reason' => null]
            : ['status' => MerchantPaymentMethod::VERIFIED, 'verified_by' => $actor->id, 'verified_at' => now(), 'rejected_reason' => null];
    }

    private function makePreferred(MerchantPaymentMethod $method): void
    {
        MerchantPaymentMethod::where('merchant_id', $method->merchant_id)->whereKeyNot($method->id)->update(['is_preferred' => false]);
        $method->update(['is_preferred' => true]);
    }

    private function log(User $actor, MerchantPaymentMethod $method, string $action, string $summary): void
    {
        MerchantPaymentMethodEvent::create([
            'company_id' => $method->company_id,
            'merchant_id' => $method->merchant_id,
            'payment_method_id' => $method->exists ? $method->id : null,
            'action' => $action,
            'summary' => mb_substr($summary, 0, 200),
            'user_id' => $actor->id,
            'by_merchant' => $actor->merchant_id !== null,
        ]);
    }

    /**
     * Sécurité : le client est toujours prévenu ; un changement venant de lui alerte la caisse.
     */
    private function announce(Merchant $merchant, string $action, string $summary, bool $byMerchant): void
    {
        $word = ['added' => 'ajouté', 'updated' => 'modifié', 'removed' => 'retiré'][$action];
        DB::afterCommit(fn () => $this->messenger->merchantMessage($merchant, 'payment_method.'.$action, WhatsAppTemplate::PaymentMethodChanged, [
            $merchant->business_name, $word, $summary,
        ]));

        if ($byMerchant) {
            $staff = User::forCompany($merchant->company_id)->where('status', 'active')->whereNull('merchant_id')
                ->role([Role::Admin->value, Role::Cashier->value])->get();
            Notification::send($staff, new MerchantPaymentMethodChanged($merchant, $action, $summary));
        }
    }

    private function storeQr(MerchantPaymentMethod $method, UploadedFile $qr): void
    {
        $path = $qr->store("payment-methods/{$method->company_id}/{$method->merchant_id}", 'local');
        $method->update(['qr_path' => $path, 'qr_mime' => $qr->getMimeType()]);
    }

    private function deleteQr(MerchantPaymentMethod $method): void
    {
        if ($method->qr_path) {
            Storage::disk('local')->delete($method->qr_path);
            $method->update(['qr_path' => null, 'qr_mime' => null]);
        }
    }
}
