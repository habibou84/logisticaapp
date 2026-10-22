<?php

namespace App\Services\Merchants;

use App\Enums\OrderStatus;
use App\Models\Merchant;
use App\Models\OrderEvent;
use Illuminate\Support\Collection;

/**
 * Position de ramassage des marchands. Elle vient du marchand (sur place, GPS de son
 * téléphone), de l'agence (carte ou lien Google Maps), ou, à défaut, des ramassages :
 * là où les livreurs ont marqué ses colis « Récupéré ». Une position donnée par le
 * marchand ou l'agence n'est jamais remplacée par celle des livreurs.
 */
class MerchantLocation
{
    // Ramassages concordants nécessaires pour estimer la position
    public const MIN_PICKUPS = 2;

    // Distance maximale (m) entre un ramassage et le centre pour qu'il compte
    public const RADIUS_METERS = 150;

    // Ramassages récents pris en compte
    private const SAMPLE = 20;

    public function set(Merchant $merchant, float $lat, float $lng, string $source, ?int $accuracy = null): Merchant
    {
        $merchant->forceFill([
            'pickup_lat' => round($lat, 7),
            'pickup_lng' => round($lng, 7),
            'pickup_location_source' => $source,
            'pickup_located_at' => now(),
            'pickup_location_accuracy' => $accuracy,
        ])->save();

        return $merchant;
    }

    /**
     * Estime la position d'après les ramassages des livreurs, si le marchand n'en a pas
     * (ou seulement une estimation). Renvoie vrai si la position a été remplie ou mise à jour.
     */
    public function learn(Merchant $merchant): bool
    {
        if ($merchant->hasPickupLocation() && $merchant->pickup_location_source !== Merchant::LOCATION_COURIER) {
            return false;
        }

        $estimate = $this->estimate($this->pickupPoints($merchant));
        if ($estimate === null) {
            return false;
        }

        $this->set($merchant, $estimate['lat'], $estimate['lng'], Merchant::LOCATION_COURIER, $estimate['accuracy']);

        return true;
    }

    /**
     * Rattrapage : estime la position de tous les marchands qui n'en ont pas.
     *
     * @return int marchands localisés
     */
    public function learnAll(): int
    {
        $count = 0;
        Merchant::query()->withoutGlobalScopes()
            ->where(fn ($q) => $q->whereNull('pickup_lat')->orWhere('pickup_location_source', Merchant::LOCATION_COURIER))
            ->each(function (Merchant $merchant) use (&$count) {
                $count += (int) $this->learn($merchant);
            });

        return $count;
    }

    /**
     * Positions GPS des livreurs au moment du ramassage des colis du marchand
     * (hors courses à ramasser ailleurs qu'à son adresse habituelle).
     *
     * @return Collection<int, array{0: float, 1: float}>
     */
    private function pickupPoints(Merchant $merchant): Collection
    {
        return OrderEvent::query()
            ->join('orders', 'orders.id', '=', 'order_events.order_id')
            ->where('orders.merchant_id', $merchant->id)
            ->whereNull('orders.pickup_hub_id')
            ->where(fn ($q) => $q->whereNull('orders.pickup_address')->orWhere('orders.pickup_address', $merchant->pickup_address))
            ->where('order_events.to_status', OrderStatus::PickedUp->value)
            ->whereNotNull('order_events.lat')->whereNotNull('order_events.lng')
            ->latest('order_events.id')
            ->limit(self::SAMPLE)
            ->get(['order_events.lat', 'order_events.lng'])
            ->map(fn ($e) => [(float) $e->lat, (float) $e->lng]);
    }

    /**
     * Centre des ramassages concordants : médiane, puis moyenne des points proches d'elle.
     *
     * @param  Collection<int, array{0: float, 1: float}>  $points
     * @return array{lat: float, lng: float, accuracy: int}|null
     */
    public function estimate(Collection $points): ?array
    {
        if ($points->count() < self::MIN_PICKUPS) {
            return null;
        }

        $median = [self::median($points->pluck(0)), self::median($points->pluck(1))];
        $close = $points->filter(fn ($p) => self::meters($median[0], $median[1], $p[0], $p[1]) <= self::RADIUS_METERS);
        if ($close->count() < self::MIN_PICKUPS) {
            return null;
        }

        $lat = $close->avg(0);
        $lng = $close->avg(1);

        return [
            'lat' => $lat,
            'lng' => $lng,
            'accuracy' => (int) max(10, round($close->max(fn ($p) => self::meters($lat, $lng, $p[0], $p[1])))),
        ];
    }

    private static function median(Collection $values): float
    {
        $sorted = $values->sort()->values();
        $n = $sorted->count();

        return $n % 2 ? $sorted[intdiv($n, 2)] : ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2;
    }

    /**
     * Distance à vol d'oiseau en mètres (haversine).
     */
    public static function meters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
