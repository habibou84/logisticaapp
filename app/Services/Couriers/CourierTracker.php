<?php

namespace App\Services\Couriers;

use App\Enums\OrderStatus;
use App\Models\Courier;
use App\Models\CourierLocation;
use App\Models\OrderEvent;
use Illuminate\Support\Carbon;

/**
 * Historique des trajets : enregistre les positions envoyées pendant le service
 * et reconstitue la journée d'un livreur (tracé, étapes des courses, distance).
 */
class CourierTracker
{
    // Positions trop imprécises ignorées (mètres)
    private const MAX_ACCURACY = 150;

    // À l'arrêt : un point au plus toutes les 5 minutes
    private const STILL_METERS = 15;

    private const STILL_SECONDS = 300;

    // Au-delà, un saut entre deux points est une erreur de GPS (km/h)
    private const MAX_SPEED_KMH = 130;

    public function record(Courier $courier, float $lat, float $lng, ?int $accuracy = null): ?CourierLocation
    {
        if ($accuracy !== null && $accuracy > self::MAX_ACCURACY) {
            return null;
        }

        $last = CourierLocation::forCompany($courier->company_id)->where('courier_id', $courier->id)->latest('recorded_at')->first();

        if ($last) {
            $meters = CourierLocation::distance($last->lat, $last->lng, $lat, $lng);
            $seconds = max(1, $last->recorded_at->diffInSeconds(now()));

            // À l'arrêt (point inutile) ou saut impossible (erreur de GPS)
            if (($seconds < self::STILL_SECONDS && $meters < self::STILL_METERS) || $meters / $seconds * 3.6 > self::MAX_SPEED_KMH) {
                return null;
            }
        }

        return CourierLocation::create([
            'company_id' => $courier->company_id,
            'courier_id' => $courier->id,
            'lat' => $lat,
            'lng' => $lng,
            'accuracy' => $accuracy,
            'recorded_at' => now(),
        ]);
    }

    /**
     * Journée d'un livreur : points du tracé, étapes des courses et résumé.
     *
     * @return array<string, mixed>
     */
    public function day(Courier $courier, Carbon $date): array
    {
        $from = $date->copy()->startOfDay();
        $to = $date->copy()->endOfDay();

        $points = CourierLocation::forCompany($courier->company_id)
            ->where('courier_id', $courier->id)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->get(['lat', 'lng', 'accuracy', 'recorded_at']);

        $events = OrderEvent::query()
            ->where('actor_id', $courier->user_id)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('to_status')
            ->with(['order' => fn ($q) => $q->withoutGlobalScopes()->select(['id', 'tracking_code', 'recipient_name', 'delivery_zone_id']), 'incidentReason'])
            ->orderBy('id')
            ->get();

        $stops = $events->filter(fn (OrderEvent $e) => $e->lat !== null && $e->lng !== null)->map(fn (OrderEvent $e) => [
            'lat' => (float) $e->lat,
            'lng' => (float) $e->lng,
            'at' => $e->created_at,
            'status' => $e->to_status->value,
            'label' => $e->to_status->label(),
            'incident' => $e->incidentReason?->label,
            'order_id' => $e->order_id,
            'tracking_code' => $e->order?->tracking_code,
            'recipient' => $e->order?->recipient_name,
        ])->values();

        return [
            'date' => $date->toDateString(),
            'points' => $points->map(fn (CourierLocation $p) => [$p->lat, $p->lng, $p->recorded_at->toIso8601String()])->values(),
            'stops' => $stops,
            'summary' => [
                'distance_km' => round($this->distance($points) / 1000, 1),
                'first_at' => $points->first()?->recorded_at,
                'last_at' => $points->last()?->recorded_at,
                'points' => $points->count(),
                'picked_up' => $events->where('to_status', OrderStatus::PickedUp)->count(),
                'delivered' => $events->where('to_status', OrderStatus::Delivered)->count(),
                'failed' => $events->whereIn('to_status', [OrderStatus::DeliveryFailed, OrderStatus::Rescheduled])->count(),
            ],
            // Jours récents avec un trajet (sélecteur de date)
            'recent_days' => CourierLocation::forCompany($courier->company_id)
                ->where('courier_id', $courier->id)
                ->where('recorded_at', '>=', now()->subDays(30)->startOfDay())
                ->selectRaw('DATE(recorded_at) AS day')
                ->groupByRaw('DATE(recorded_at)')
                ->orderByDesc('day')
                ->pluck('day')
                ->map(fn ($d) => substr((string) $d, 0, 10))
                ->values(),
        ];
    }

    /**
     * Distance parcourue en mètres, sans les sauts impossibles (erreurs de GPS).
     *
     * @param  iterable<CourierLocation>  $points
     */
    private function distance(iterable $points): float
    {
        $total = 0.0;
        $previous = null;

        foreach ($points as $point) {
            if ($previous) {
                $meters = CourierLocation::distance($previous->lat, $previous->lng, $point->lat, $point->lng);
                $seconds = max(1, $previous->recorded_at->diffInSeconds($point->recorded_at));

                // Point aberrant : ignoré, le suivant repart du dernier point fiable
                if ($meters / $seconds * 3.6 > self::MAX_SPEED_KMH) {
                    continue;
                }

                $total += $meters;
            }
            $previous = $point;
        }

        return $total;
    }
}
