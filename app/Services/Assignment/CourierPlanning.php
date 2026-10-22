<?php

namespace App\Services\Assignment;

use App\Models\Courier;
use App\Models\CourierAbsence;
use App\Models\CourierRule;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Zone;
use Illuminate\Support\Collection;

/**
 * Planning des livreurs : qui ramasse (ou livre) où et quand.
 *
 * Une règle vise des zones (une commune couvre ses quartiers) ou des clients, tous les jours, certains jours
 * de la semaine, à une date ou sur une période. Quand plusieurs règles s'appliquent, la plus précise l'emporte :
 * un client avant une zone, un quartier avant sa commune, une date avant une période, avant des jours de la
 * semaine, avant « tous les jours ». Livreur absent, en pause ou inactif : son remplaçant prend le relais.
 */
class CourierPlanning
{
    /**
     * Livreur du planning pour une course, ce jour-là (AAAA-MM-JJ), ou null si personne ne la couvre.
     * $exclude : livreurs écartés pour cette course (refus, empêchement, injoignable) : traités comme absents.
     *
     * @param  list<int>  $exclude
     * @return array{courier: Courier, rule: CourierRule, backup: bool, label: string}|null
     */
    public function resolve(Order $order, string $type, string $date, array $exclude = []): ?array
    {
        $zoneId = $type === CourierRule::PICKUP ? $order->pickup_zone_id : $order->delivery_zone_id;
        $merchantId = $type === CourierRule::PICKUP ? $order->merchant_id : null;

        return $this->resolveFor($order->company_id, $type, $date, $zoneId, $merchantId, $exclude);
    }

    /**
     * @param  list<int>  $exclude
     * @return array{courier: Courier, rule: CourierRule, backup: bool, label: string}|null
     */
    public function resolveFor(int $companyId, string $type, string $date, ?int $zoneId, ?int $merchantId, array $exclude = []): ?array
    {
        $chain = $zoneId ? $this->zoneChain($companyId, $zoneId) : [];
        $rule = $this->rulesOn($companyId, $type, $date)
            ->map(fn (CourierRule $r) => ['rule' => $r, 'score' => $this->matchScore($r, $merchantId, $chain)])
            ->filter(fn ($m) => $m['score'] !== null)
            ->sortBy([['score', 'desc'], [fn ($m) => $m['rule']->id, 'asc']])
            ->first()['rule'] ?? null;

        if ($rule === null) {
            return null;
        }

        $absent = [...$this->absentOn($companyId, $date), ...$exclude];
        if ($this->available($rule->courier, $absent)) {
            return ['courier' => $rule->courier, 'rule' => $rule, 'backup' => false, 'label' => $this->describe($rule)];
        }
        if ($rule->backup && $this->available($rule->backup, $absent)) {
            return ['courier' => $rule->backup, 'rule' => $rule, 'backup' => true, 'label' => $this->describe($rule)];
        }

        return null;
    }

    /**
     * Règles actives ce jour-là, avec livreur et remplaçant.
     *
     * @return Collection<int, CourierRule>
     */
    public function rulesOn(int $companyId, string $type, string $date): Collection
    {
        return CourierRule::forCompany($companyId)
            ->where('type', $type)
            ->where('is_active', true)
            ->with(['courier.user.company', 'backup.user.company'])
            ->get()
            ->filter(fn (CourierRule $r) => $r->appliesOn($date))
            ->values();
    }

    /**
     * Livreurs absents ce jour-là (identifiants).
     *
     * @return list<int>
     */
    public function absentOn(int $companyId, string $date): array
    {
        return CourierAbsence::forCompany($companyId)
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->pluck('courier_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Le livreur peut-il recevoir des missions : compte actif, disponible, pas absent.
     *
     * @param  list<int>  $absent
     */
    public function available(?Courier $courier, array $absent): bool
    {
        return $courier !== null && $courier->is_available && (bool) $courier->user?->isActive() && ! in_array($courier->id, $absent, true);
    }

    /**
     * Précision de la règle pour cette course, ou null si elle ne la couvre pas.
     *
     * @param  list<int>  $chain  zone de la course puis ses zones parentes
     */
    public function matchScore(CourierRule $rule, ?int $merchantId, array $chain): ?int
    {
        if ($rule->targetsMerchants()) {
            if ($merchantId === null || ! in_array($merchantId, array_map('intval', $rule->merchant_ids), true)) {
                return null;
            }
            $target = 300;
        } else {
            $ids = array_map('intval', $rule->zone_ids ?? []);
            $depth = collect($chain)->search(fn ($z) => in_array($z, $ids, true));
            if ($depth === false) {
                return null;
            }
            // Le quartier lui-même avant sa commune
            $target = 200 - (int) $depth * 10;
        }

        return $target + $this->timeScore($rule);
    }

    /** Une date avant une période, avant des jours de la semaine, avant tous les jours */
    public function timeScore(CourierRule $rule): int
    {
        if ($rule->starts_on && $rule->ends_on && $rule->starts_on->equalTo($rule->ends_on)) {
            return 3;
        }
        if ($rule->starts_on || $rule->ends_on) {
            return 2;
        }

        return empty($rule->days) ? 0 : 1;
    }

    /**
     * Règles qui contrediraient celle-ci : même type, même cible, même précision, jours communs, autre livreur.
     *
     * @param  array<string, mixed>  $data  champs de la règle (zone_ids, merchant_ids, days, starts_on, ends_on, courier_id, type)
     * @return list<string>
     */
    public function conflicts(int $companyId, array $data, ?int $ignoreId = null): array
    {
        $candidate = new CourierRule(['is_active' => true, ...$data]);

        return CourierRule::forCompany($companyId)
            ->where('type', $data['type'])
            ->where('is_active', true)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->with('courier.user')
            ->get()
            ->filter(fn (CourierRule $r) => (int) $r->courier_id !== (int) $data['courier_id']
                && $this->sameTarget($r, $candidate)
                && $this->timeScore($r) === $this->timeScore($candidate)
                && $this->overlap($r, $candidate))
            ->map(fn (CourierRule $r) => "Règle de {$r->courier?->user?->name} : {$this->describe($r)}")
            ->values()->all();
    }

    /**
     * Texte d'une règle : « Cocody, Riviera · lundi, jeudi » ; « Boutique Chic · le 25/10 ».
     */
    public function describe(CourierRule $rule): string
    {
        $where = $rule->targetsMerchants()
            ? Merchant::withoutGlobalScopes()->whereIn('id', $rule->merchant_ids)->orderBy('business_name')->pluck('business_name')->join(', ')
            : Zone::withoutGlobalScopes()->whereIn('id', $rule->zone_ids ?? [])->orderBy('name')->pluck('name')->join(', ');

        return ($where !== '' ? $where : '—').' · '.$this->when($rule);
    }

    public function when(CourierRule $rule): string
    {
        $names = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];
        $days = collect($rule->days ?? [])->map(fn ($d) => (int) $d)->sort()->map(fn ($d) => $names[$d] ?? '')->join(', ');
        $from = $rule->starts_on?->format('d/m/Y');
        $to = $rule->ends_on?->format('d/m/Y');

        $period = match (true) {
            $from !== null && $from === $to => "le {$from}",
            $from !== null && $to !== null => "du {$from} au {$to}",
            $from !== null => "à partir du {$from}",
            $to !== null => "jusqu'au {$to}",
            default => null,
        };
        $single = $from !== null && $from === $to;
        // Une date seule se suffit ; sinon les jours (ou « tous les jours »), puis la période
        $base = $days !== '' ? $days : ($single ? null : 'tous les jours');

        return implode(' · ', array_filter([$base, $period]));
    }

    /**
     * La zone puis ses parents (quartier, commune…).
     *
     * @return list<int>
     */
    public function zoneChain(int $companyId, int $zoneId): array
    {
        $parents = Zone::withoutGlobalScopes()->where('company_id', $companyId)->pluck('parent_id', 'id');
        $chain = [];
        for ($id = $zoneId; $id !== null && ! in_array($id, $chain, true) && count($chain) < 6; $id = $parents[$id] ?? null) {
            $chain[] = (int) $id;
        }

        return $chain;
    }

    private function sameTarget(CourierRule $a, CourierRule $b): bool
    {
        if ($a->targetsMerchants() !== $b->targetsMerchants()) {
            return false;
        }
        $left = array_map('intval', ($a->targetsMerchants() ? $a->merchant_ids : $a->zone_ids) ?? []);
        $right = array_map('intval', ($b->targetsMerchants() ? $b->merchant_ids : $b->zone_ids) ?? []);

        return array_intersect($left, $right) !== [];
    }

    private function overlap(CourierRule $a, CourierRule $b): bool
    {
        $start = max($a->starts_on?->toDateString() ?? '0000-01-01', $b->starts_on?->toDateString() ?? '0000-01-01');
        $end = min($a->ends_on?->toDateString() ?? '9999-12-31', $b->ends_on?->toDateString() ?? '9999-12-31');
        if ($start > $end) {
            return false;
        }
        $daysA = array_map('intval', $a->days ?? []) ?: range(1, 7);
        $daysB = array_map('intval', $b->days ?? []) ?: range(1, 7);

        return array_intersect($daysA, $daysB) !== [];
    }
}
