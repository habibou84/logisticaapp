<?php

namespace App\Services\FieldEvents;

use App\Models\FieldEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Statistiques des événements terrain sur une période : nombre par type, temps de prise en charge et de
 * résolution, immobilisation des livreurs, coût des dépannages, livreurs et zones les plus touchés.
 */
class FieldEventStats
{
    /**
     * @return array<string, mixed>
     */
    public function summary(string $from, string $to, ?int $courierId = null, ?int $companyId = null): array
    {
        $events = ($companyId ? FieldEvent::forCompany($companyId) : FieldEvent::query())->with('courier.user')
            ->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)
            ->when($courierId, fn ($q) => $q->where('courier_id', $courierId))
            ->orderByDesc('id')->get();

        $minutes = fn ($a, $b) => $a && $b ? max(0, (int) round($a->diffInMinutes($b, true))) : null;
        $taken = $events->map(fn (FieldEvent $e) => $minutes($e->created_at, $e->taken_at))->filter(fn ($m) => $m !== null);
        $resolved = $events->map(fn (FieldEvent $e) => $minutes($e->created_at, $e->resolved_at))->filter(fn ($m) => $m !== null);

        return [
            'totals' => [
                'events' => $events->count(),
                'urgent' => $events->where('severity', 'urgent')->count(),
                'blocking' => $events->where('capacity', '!=', 'continue')->count(),
                'open' => $events->where('status', '!=', FieldEvent::RESOLVED)->count(),
                'take_minutes' => $taken->isNotEmpty() ? (int) round($taken->avg()) : null,
                'resolve_minutes' => $resolved->isNotEmpty() ? (int) round($resolved->avg()) : null,
                'immobilised_minutes' => (int) $events->sum(fn (FieldEvent $e) => $this->immobilised($e)),
                'cost' => (int) $events->sum('cost'),
            ],
            'by_type' => $events->groupBy('type')
                ->map(fn (Collection $g) => ['label' => trim($g->first()->icon.' '.$g->first()->type_label), 'name' => $g->first()->type_label, 'count' => $g->count()])
                ->sortByDesc('count')->values()->all(),
            'by_courier' => $events->groupBy('courier_id')
                ->map(fn (Collection $g) => [
                    'id' => $g->first()->courier_id,
                    'name' => $g->first()->courier?->user?->name,
                    'count' => $g->count(),
                    'blocking' => $g->where('capacity', '!=', 'continue')->count(),
                    'immobilised_minutes' => (int) $g->sum(fn (FieldEvent $e) => $this->immobilised($e)),
                    'cost' => (int) $g->sum('cost'),
                    'types' => $g->countBy('type_label')->sortDesc()->all(),
                ])
                ->sortByDesc('count')->values()->all(),
            'by_zone' => $this->zones($events->modelKeys()),
            'recent' => $events->take($courierId ? 20 : 10)->map(fn (FieldEvent $e) => [
                'id' => $e->id,
                'icon' => $e->icon,
                'type_label' => $e->type_label,
                'severity' => $e->severity,
                'capacity' => $e->capacity,
                'status' => $e->status,
                'note' => $e->note,
                'cost' => $e->cost,
                'created_at' => $e->created_at,
                'immobilised_minutes' => $this->immobilised($e),
            ])->values()->all(),
        ];
    }

    /**
     * Ligne du point de la semaine, ex. « 5 événements terrain (Panne 3, Accident 1), immobilisation 4 h ». Vide sans événement.
     */
    public function weeklyLine(int $companyId, string $from, string $to): string
    {
        $s = $this->summary($from, $to, null, $companyId);
        if ($s['totals']['events'] === 0) {
            return '';
        }
        $types = collect($s['by_type'])->map(fn ($t) => "{$t['name']} {$t['count']}")->implode(', ');
        $minutes = $s['totals']['immobilised_minutes'];
        $hours = $minutes >= 60 ? intdiv($minutes, 60).' h'.($minutes % 60 ? ' '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) : '') : "{$minutes} min";

        return "{$s['totals']['events']} événement(s) terrain ({$types})".($minutes ? ", immobilisation {$hours}" : '');
    }

    /**
     * Temps où le livreur n'a pas pu rouler : du signalement à sa reprise (ou à la résolution, ou à l'heure
     * qu'il avait estimée), pour un événement qui le bloquait.
     */
    public function immobilised(FieldEvent $event): int
    {
        if ($event->capacity === 'continue') {
            return 0;
        }
        $now = CarbonImmutable::now();
        $end = $event->resumed_at ?? $event->resolved_at ?? ($event->blocked_until && $event->blocked_until->lt($now) ? $event->blocked_until : $now);

        return max(0, (int) round($event->created_at->diffInMinutes($end, true)));
    }

    /**
     * Zones de livraison des courses touchées (une fois par événement).
     *
     * @param  list<int>  $eventIds
     * @return list<array{label: string, count: int}>
     */
    private function zones(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        return DB::table('field_event_order')
            ->join('orders', 'orders.id', '=', 'field_event_order.order_id')
            ->join('zones', 'zones.id', '=', 'orders.delivery_zone_id')
            ->whereIn('field_event_order.field_event_id', $eventIds)
            ->select('zones.name', DB::raw('COUNT(DISTINCT field_event_order.field_event_id) as total'))
            ->groupBy('zones.name')->orderByDesc('total')->limit(10)->get()
            ->map(fn ($r) => ['label' => $r->name, 'count' => (int) $r->total])->all();
    }
}
