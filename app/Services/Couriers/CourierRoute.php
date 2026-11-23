<?php

namespace App\Services\Couriers;

use App\Enums\AssignmentType;
use App\Models\Courier;
use App\Models\CourierLocation;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Services\Orders\DeliveryDayRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Itinéraire du livreur : tous ses arrêts en cours (ramassages ou collectes, livraisons, retours) dans le meilleur ordre, calculé à vol d'oiseau (gratuit, sans service
 * extérieur) depuis sa position. Le livreur l'organise lui-même et peut changer l'ordre à la main ; l'ordre est gardé
 * sur ses missions (liste « Livrer » de son application).
 *  - position de l'arrêt : celle de l'adresse (course, ou destination de l'annuaire) ; sinon approximative, au milieu
 *    des livraisons déjà faites dans le même quartier ; sinon inconnue (placée à la fin) ;
 *  - livraisons à la même adresse (moins de SAME_PLACE_METERS) : un seul arrêt ;
 *  - colis express d'abord, puis le plus court chemin (plus proche voisin, puis amélioré en décroisant les trajets) ;
 *  - un colis est toujours ramassé avant d'être livré : sa livraison ne passe jamais avant son ramassage.
 */
class CourierRoute
{
    public const SAME_PLACE_METERS = 40;

    /** Livraisons faites prises en compte pour situer un quartier */
    private const ZONE_HISTORY_DAYS = 180;

    public function __construct(private readonly DeliveryDayRule $days) {}

    /**
     * Tournée telle qu'elle est (ordre gardé, sinon ordre d'arrivée des missions).
     *
     * @return array<string, mixed>
     */
    public function show(Courier $courier, ?float $lat = null, ?float $lng = null): array
    {
        $stops = $this->group($this->assignments($courier));
        $ordered = $stops->sortBy(fn (array $s) => [$s['position'] === null ? 1 : 0, $s['position'] ?? 0, $s['first_assigned']])->values();

        return $this->present($ordered, $lat, $lng);
    }

    /**
     * Calcule le meilleur ordre depuis la position du livreur et le garde.
     *
     * @return array<string, mixed>
     */
    public function optimize(Courier $courier, ?float $lat, ?float $lng): array
    {
        $stops = $this->group($this->assignments($courier));
        $ordered = $this->save($this->pickupFirst($this->solve($stops, $lat, $lng)));

        return $this->present($ordered, $lat, $lng);
    }

    /**
     * Ordre choisi à la main : liste des arrêts (identifiants de leurs missions, dans l'ordre).
     *
     * @param  list<int>  $assignmentIds
     * @return array<string, mixed>
     */
    public function reorder(Courier $courier, array $assignmentIds, ?float $lat = null, ?float $lng = null): array
    {
        $stops = $this->group($this->assignments($courier));
        $rank = array_flip(array_map('intval', $assignmentIds));
        // Un arrêt prend la place de la première de ses missions dans la liste reçue ; les autres à la fin
        $ordered = $stops->sortBy(fn (array $s) => [
            collect($s['assignment_ids'])->map(fn ($id) => $rank[$id] ?? PHP_INT_MAX)->min(),
            $s['first_assigned'],
        ])->values();

        return $this->present($this->save($this->pickupFirst($ordered)), $lat, $lng);
    }

    /**
     * Missions en cours du livreur : ramassages, retours, et livraisons prévues aujourd'hui (même règle que sa liste).
     *
     * @return Collection<int, OrderAssignment>
     */
    private function assignments(Courier $courier): Collection
    {
        return OrderAssignment::query()->where('courier_id', $courier->id)->active()
            ->with(['order' => fn ($q) => $q->with(['deliveryZone:id,name', 'pickupZone:id,name', 'destination:id,lat,lng', 'merchant:id,business_name,pickup_lat,pickup_lng'])])
            ->orderBy('assigned_at')->get()
            ->filter(fn (OrderAssignment $a) => $a->order !== null && ($a->type !== AssignmentType::Delivery || $this->days->allows($a->order)))
            ->values();
    }

    /**
     * Missions regroupées par lieu (un lieu, un arrêt), avec leur position.
     *
     * @param  Collection<int, OrderAssignment>  $assignments
     * @return Collection<int, array<string, mixed>>
     */
    private function group(Collection $assignments): Collection
    {
        $centers = $this->zoneCenters($assignments);
        $stops = [];
        foreach ($assignments as $a) {
            $order = $a->order;
            $delivery = $a->type === AssignmentType::Delivery;
            [$lat, $lng, $precision] = $delivery ? $this->point($order, $centers) : $this->pickupPoint($order);
            $key = null;
            foreach ($stops as $i => $stop) {
                if ($precision === 'exact' && $stop['precision'] === 'exact'
                    && CourierLocation::distance($lat, $lng, $stop['lat'], $stop['lng']) <= self::SAME_PLACE_METERS) {
                    $key = $i;
                    break;
                }
            }
            $item = [
                'assignment_id' => $a->id,
                'order_id' => $order->id,
                // pickup (ramasser ou collecter), delivery (livrer), return (rendre au client)
                'type' => $a->type->value,
                'tracking_code' => $order->tracking_code,
                // Chez qui l'on passe : le destinataire pour une livraison, le client sinon
                'recipient' => $delivery ? ($order->recipient_name ?: $order->recipient_phone) : ($order->merchant?->business_name ?: $order->pickup_contact_name),
                'is_express' => (bool) $order->is_express,
                'time_slot' => $order->delivery_time_slot,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
            ];
            if ($key !== null) {
                $stops[$key]['assignment_ids'][] = $a->id;
                $stops[$key]['orders'][] = $item;
                $stops[$key]['is_express'] = $stops[$key]['is_express'] || $item['is_express'];
                $stops[$key]['position'] = min(array_filter([$stops[$key]['position'], $a->route_position], fn ($v) => $v !== null) ?: [null]);

                continue;
            }
            $stops[] = [
                'assignment_ids' => [$a->id],
                'orders' => [$item],
                'address' => trim(implode(', ', array_filter($delivery ? [$order->delivery_address, $order->delivery_landmark] : [$order->pickup_address, $order->pickup_landmark]))) ?: null,
                'zone' => $delivery ? $order->deliveryZone?->name : $order->pickupZone?->name,
                'lat' => $lat,
                'lng' => $lng,
                'precision' => $precision,
                'is_express' => $item['is_express'],
                'position' => $a->route_position,
                'first_assigned' => $a->assigned_at?->timestamp ?? 0,
            ];
        }

        return collect($stops);
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $centers
     * @return array{0: float|null, 1: float|null, 2: string}
     */
    private function point(Order $order, array $centers): array
    {
        if ($order->delivery_lat !== null && $order->delivery_lng !== null) {
            return [(float) $order->delivery_lat, (float) $order->delivery_lng, 'exact'];
        }
        if ($order->destination?->lat !== null && $order->destination?->lng !== null) {
            return [(float) $order->destination->lat, (float) $order->destination->lng, 'exact'];
        }
        if (isset($centers[$order->delivery_zone_id])) {
            return [...$centers[$order->delivery_zone_id], 'zone'];
        }

        return [null, null, 'unknown'];
    }

    /**
     * Ramassage, collecte ou retour : chez le client (position de la course, sinon celle de sa fiche).
     *
     * @return array{0: float|null, 1: float|null, 2: string}
     */
    private function pickupPoint(Order $order): array
    {
        if ($order->pickup_lat !== null && $order->pickup_lng !== null) {
            return [(float) $order->pickup_lat, (float) $order->pickup_lng, 'exact'];
        }
        if ($order->merchant?->pickup_lat !== null && $order->merchant?->pickup_lng !== null) {
            return [(float) $order->merchant->pickup_lat, (float) $order->merchant->pickup_lng, 'exact'];
        }

        return [null, null, 'unknown'];
    }

    /**
     * Un colis est ramassé avant d'être livré : une livraison placée avant le ramassage du même colis descend juste
     * après lui.
     *
     * @param  Collection<int, array<string, mixed>>  $stops
     * @return Collection<int, array<string, mixed>>
     */
    private function pickupFirst(Collection $stops): Collection
    {
        $list = $stops->values()->all();
        $has = fn (array $stop, string $type, int $orderId) => collect($stop['orders'])->contains(fn (array $o) => $o['type'] === $type && $o['order_id'] === $orderId);
        // Chaque passage règle au moins un colis : au plus un passage par arrêt
        for ($pass = 0, $moved = true; $moved && $pass <= count($list); $pass++) {
            $moved = false;
            foreach ($list as $i => $stop) {
                foreach ($stop['orders'] as $o) {
                    if ($o['type'] !== 'delivery') {
                        continue;
                    }
                    $pickup = null;
                    foreach ($list as $j => $other) {
                        if ($j > $i && $has($other, 'pickup', $o['order_id'])) {
                            $pickup = $j;
                        }
                    }
                    if ($pickup !== null) {
                        array_splice($list, $i, 1);
                        array_splice($list, $pickup, 0, [$stop]);
                        $moved = true;

                        continue 3;
                    }
                }
            }
        }

        return collect($list);
    }

    /**
     * Milieu de chaque quartier : moyenne des positions des livraisons qui y ont été faites.
     *
     * @param  Collection<int, OrderAssignment>  $assignments
     * @return array<int, array{0: float, 1: float}>
     */
    private function zoneCenters(Collection $assignments): array
    {
        $zones = $assignments->where('type', AssignmentType::Delivery)->map(fn (OrderAssignment $a) => $a->order->delivery_zone_id)->filter()->unique()->values();
        if ($zones->isEmpty()) {
            return [];
        }

        return Order::withoutGlobalScopes()->where('company_id', $assignments->first()->order->company_id)
            ->whereIn('delivery_zone_id', $zones)->whereNotNull('delivery_lat')->whereNotNull('delivery_lng')
            ->where('created_at', '>=', now()->subDays(self::ZONE_HISTORY_DAYS))
            ->groupBy('delivery_zone_id')
            ->select('delivery_zone_id', DB::raw('AVG(delivery_lat) as lat'), DB::raw('AVG(delivery_lng) as lng'))
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->delivery_zone_id => [(float) $row->lat, (float) $row->lng]])
            ->all();
    }

    /**
     * Express d'abord, puis le reste ; chaque bloc : plus proche voisin depuis le point courant, puis décroisement.
     * Arrêts sans position : à la fin, dans l'ordre d'arrivée.
     *
     * @param  Collection<int, array<string, mixed>>  $stops
     * @return Collection<int, array<string, mixed>>
     */
    private function solve(Collection $stops, ?float $lat, ?float $lng): Collection
    {
        $located = $stops->filter(fn (array $s) => $s['lat'] !== null)->values();
        $unknown = $stops->filter(fn (array $s) => $s['lat'] === null)->sortBy('first_assigned')->values();

        $start = $lat !== null && $lng !== null ? [$lat, $lng] : null;
        $out = [];
        foreach ([$located->where('is_express', true)->values(), $located->where('is_express', false)->values()] as $block) {
            if ($block->isEmpty()) {
                continue;
            }
            $path = $this->improve($this->nearest($block->all(), $start), $start);
            $out = [...$out, ...$path];
            $last = end($path);
            $start = [$last['lat'], $last['lng']];
        }

        return collect([...$out, ...$unknown->all()]);
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     * @param  array{0: float, 1: float}|null  $from
     * @return list<array<string, mixed>>
     */
    private function nearest(array $stops, ?array $from): array
    {
        $left = $stops;
        $path = [];
        // Sans position du livreur : on part de l'arrêt le plus ancien
        if ($from === null) {
            usort($left, fn ($a, $b) => $a['first_assigned'] <=> $b['first_assigned']);
            $path[] = array_shift($left);
            $from = [$path[0]['lat'], $path[0]['lng']];
        }
        while ($left !== []) {
            $best = null;
            $bestDistance = INF;
            foreach ($left as $i => $s) {
                $d = CourierLocation::distance($from[0], $from[1], $s['lat'], $s['lng']);
                if ($d < $bestDistance) {
                    [$best, $bestDistance] = [$i, $d];
                }
            }
            $path[] = $left[$best];
            $from = [$left[$best]['lat'], $left[$best]['lng']];
            unset($left[$best]);
        }

        return $path;
    }

    /**
     * Décroisement (2-opt) d'un trajet ouvert : on retourne un morceau du trajet tant que ça le raccourcit.
     *
     * @param  list<array<string, mixed>>  $path
     * @param  array{0: float, 1: float}|null  $from
     * @return list<array<string, mixed>>
     */
    private function improve(array $path, ?array $from): array
    {
        $n = count($path);
        if ($n < 3) {
            return $path;
        }
        $points = $from ? [['lat' => $from[0], 'lng' => $from[1]], ...$path] : $path;
        $offset = $from ? 1 : 0;
        $d = fn (array $a, array $b) => CourierLocation::distance($a['lat'], $a['lng'], $b['lat'], $b['lng']);
        $count = count($points);

        for ($round = 0, $better = true; $better && $round < 50; $round++) {
            $better = false;
            for ($i = $offset; $i < $count - 1; $i++) {
                for ($k = $i + 1; $k < $count; $k++) {
                    $before = $i > 0 ? $points[$i - 1] : null;
                    $after = $k < $count - 1 ? $points[$k + 1] : null;
                    $old = ($before ? $d($before, $points[$i]) : 0) + ($after ? $d($points[$k], $after) : 0);
                    $new = ($before ? $d($before, $points[$k]) : 0) + ($after ? $d($points[$i], $after) : 0);
                    if ($new + 0.5 < $old) {
                        array_splice($points, $i, $k - $i + 1, array_reverse(array_slice($points, $i, $k - $i + 1)));
                        $better = true;
                    }
                }
            }
        }

        return array_values(array_slice($points, $offset));
    }

    /**
     * Ordre gardé sur les missions : chaque arrêt prend sa place (1, 2, 3…).
     *
     * @param  Collection<int, array<string, mixed>>  $ordered
     * @return Collection<int, array<string, mixed>>
     */
    private function save(Collection $ordered): Collection
    {
        $ordered = $ordered->values()->map(fn (array $s, int $i) => [...$s, 'position' => $i + 1]);
        DB::transaction(function () use ($ordered) {
            $now = now();
            foreach ($ordered as $stop) {
                OrderAssignment::query()->whereIn('id', $stop['assignment_ids'])
                    ->update(['route_position' => $stop['position'], 'route_planned_at' => $now]);
            }
        });

        return $ordered;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $ordered
     * @return array<string, mixed>
     */
    private function present(Collection $ordered, ?float $lat, ?float $lng): array
    {
        $from = $lat !== null && $lng !== null ? [$lat, $lng] : null;
        $total = 0;
        $stops = $ordered->values()->map(function (array $s, int $i) use (&$from, &$total) {
            $leg = null;
            if ($from !== null && $s['lat'] !== null) {
                $leg = (int) round(CourierLocation::distance($from[0], $from[1], $s['lat'], $s['lng']));
                $total += $leg;
            }
            if ($s['lat'] !== null) {
                $from = [$s['lat'], $s['lng']];
            }

            return [
                'number' => $i + 1,
                'assignment_ids' => $s['assignment_ids'],
                'orders' => $s['orders'],
                'address' => $s['address'],
                'zone' => $s['zone'],
                'lat' => $s['lat'],
                'lng' => $s['lng'],
                'precision' => $s['precision'],
                'is_express' => $s['is_express'],
                'placed' => $s['position'] !== null,
                'leg_meters' => $leg,
            ];
        })->all();

        return [
            'stops' => $stops,
            'total_meters' => $total,
            'unplaced' => collect($stops)->where('placed', false)->count(),
        ];
    }
}
