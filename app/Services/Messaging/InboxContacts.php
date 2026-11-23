<?php

namespace App\Services\Messaging;

use App\Enums\Role;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Support\PhoneNumber;

/**
 * Messages : qui se cache derrière un numéro qui écrit au WhatsApp de l'entreprise.
 * Livreur ou client d'abord (comptes de l'entreprise), puis destinataire de la dernière course,
 * sinon numéro inconnu.
 */
class InboxContacts
{
    public const CLIENT = 'client';

    public const MERCHANT = 'merchant';

    public const COURIER = 'courier';

    public const UNKNOWN = 'unknown';

    /**
     * @param  list<string>  $phones
     * @return array<string, array<string, mixed>> numéro => contact
     */
    public function identify(int $companyId, array $phones): array
    {
        $phones = array_values(array_unique($phones));
        if ($phones === []) {
            return [];
        }

        $contacts = [];

        // Comptes de l'entreprise : livreurs, clients (gérant ou employé)
        $users = User::forCompany($companyId)->whereIn('phone', $phones)->with(['roles', 'merchant:id,business_name'])->get();
        foreach ($users as $user) {
            if ($user->hasRole(Role::Courier->value)) {
                $contacts[$user->phone] = $this->contact($user->phone, self::COURIER, $user->name, 'Livreur');
            } elseif ($user->merchant) {
                $contacts[$user->phone] = $this->contact($user->phone, self::MERCHANT, $user->merchant->business_name, 'Client', merchantId: $user->merchant_id);
            }
        }

        $rest = array_values(array_diff($phones, array_keys($contacts)));
        if ($rest !== []) {
            $merchants = Merchant::forCompany($companyId)
                ->where(fn ($q) => $q->whereIn('phone', $rest)->orWhereIn('whatsapp_phone', $rest))
                ->get(['id', 'business_name', 'phone', 'whatsapp_phone']);
            foreach ($merchants as $merchant) {
                foreach (array_filter([$merchant->phone, $merchant->whatsapp_phone]) as $phone) {
                    if (in_array($phone, $rest, true)) {
                        $contacts[$phone] ??= $this->contact($phone, self::MERCHANT, $merchant->business_name, 'Client', merchantId: $merchant->id);
                    }
                }
            }
        }

        // Destinataires : la course la plus récente à ce numéro
        $rest = array_values(array_diff($phones, array_keys($contacts)));
        if ($rest !== []) {
            $orders = Order::forCompany($companyId)
                ->whereIn('recipient_phone', $rest)
                ->with(['merchant:id,business_name', 'deliveryCourier.user:id,name'])
                ->latest('id')
                ->limit(500)
                ->get();
            foreach ($orders as $order) {
                $contacts[$order->recipient_phone] ??= $this->contact($order->recipient_phone, self::CLIENT, $order->recipient_name ?: null, 'Destinataire', $order);
            }
        }

        foreach ($phones as $phone) {
            $contacts[$phone] ??= $this->contact($phone, self::UNKNOWN, null, 'Numéro inconnu');
        }

        return $contacts;
    }

    /**
     * @return array<string, mixed>
     */
    private function contact(string $phone, string $kind, ?string $name, string $kindLabel, ?Order $order = null, ?int $merchantId = null): array
    {
        return [
            'phone' => $phone,
            'phone_display' => PhoneNumber::display($phone),
            'kind' => $kind,
            'kind_label' => $kindLabel,
            'name' => $name ?: PhoneNumber::display($phone),
            'merchant_id' => $merchantId ?? $order?->merchant_id,
            'order' => $order ? [
                'id' => $order->id,
                'tracking_code' => $order->tracking_code,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'merchant' => $order->merchant?->business_name,
                'courier' => $order->deliveryCourier?->user?->name,
            ] : null,
        ];
    }
}
