<?php

namespace App\Services\Labels;

use App\Models\Order;

/**
 * Lien d'étiquette sans connexion (API publique : Jibiat Marchand l'affiche à son client) : adresse de
 * l'application signée, valable quelques jours.
 */
class LabelLinks
{
    public const DAYS = 7;

    public function url(Order $order): string
    {
        $expires = now()->addDays(self::DAYS)->startOfHour()->getTimestamp();

        return $order->company->url("/etiquette-partagee/{$order->id}/{$expires}/".$this->token($order->id, $expires));
    }

    public function valid(int $orderId, int $expires, string $token): bool
    {
        return $expires >= now()->getTimestamp() && hash_equals($this->token($orderId, $expires), $token);
    }

    private function token(int $orderId, int $expires): string
    {
        return substr(hash_hmac('sha256', "label|{$orderId}|{$expires}", (string) config('app.key')), 0, 40);
    }
}
