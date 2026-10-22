<?php

namespace App\Services\Connect;

use App\Enums\CompanyStatus;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\PricingRule;
use App\Models\Zone;
use App\Support\Branding;
use Illuminate\Support\Facades\Cache;

/**
 * Annuaire public des entreprises de livraison (jibiat.com/annuaire et Jibiat Marchand) : seules celles qui
 * l'ont choisi y figurent, avec leurs villes, leur prix de départ et leurs vrais chiffres des 90 derniers jours.
 * Contrat : schéma « directory » de habibou84/socle.
 */
class Directory
{
    private const CACHE_KEY = 'directory:v1';

    /** Issues d'une course arrivée au bout : livrée ou revenue */
    private const FAILED = [OrderStatus::Returned, OrderStatus::ReturnAssigned, OrderStatus::Returning, OrderStatus::Lost];

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(10), fn () => Company::query()
            ->where('status', CompanyStatus::Active->value)
            ->where('directory_listed', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Company $company) => $this->entry($company))
            // Les entreprises les plus actives d'abord
            ->sortByDesc(fn (array $e) => $e['stats']['deliveries_90d'])
            ->values()
            ->all());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    public function entry(Company $company): array
    {
        $zones = Zone::forCompany($company->id)->active()->get(['id', 'parent_id', 'name', 'city', 'is_shipping']);
        $local = $zones->where('is_shipping', false);
        $cities = $local->pluck('city')->filter()->unique()->values();
        if ($cities->isEmpty()) {
            $cities = $local->whereNull('parent_id')->pluck('name')->unique()->values();
        }
        $logo = Branding::logoUrl($company);

        return [
            'slug' => $company->slug,
            'name' => $company->name,
            'url' => $company->url(),
            'connect_url' => $company->url('/marchand/connecter'),
            'logo_url' => $logo ? $company->url($logo) : null,
            'pitch' => $company->directory_pitch ?: null,
            'phone' => $company->phone ?: null,
            'cities' => $cities->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'zones_count' => $local->count(),
            'ships_inland' => $zones->contains('is_shipping', true),
            'price_from' => $this->priceFrom($company),
            'accepts' => $company->merchant_signup ? 'signup' : 'request',
            'member_since' => $company->created_at?->toDateString() ?? now()->toDateString(),
            'stats' => $this->stats($company),
        ];
    }

    private function priceFrom(Company $company): ?int
    {
        $price = PricingRule::query()
            ->whereHas('grid', fn ($q) => $q->withoutGlobalScopes()->where('company_id', $company->id)->where('is_default', true))
            ->where('price', '>', 0)
            ->min('price');

        return $price !== null ? (int) $price : null;
    }

    /**
     * Courses des 90 derniers jours (hors courses d'essai) : livrées, part de réussite, délai moyen.
     *
     * @return array{deliveries_90d: int, success_rate: float|null, average_delivery_hours: float|null}
     */
    private function stats(Company $company): array
    {
        $recent = Order::forCompany($company->id)->where('created_at', '>=', now()->subDays(90))->where('is_test', false);

        $delivered = (clone $recent)->where('status', OrderStatus::Delivered->value)->count();
        $failed = (clone $recent)->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, self::FAILED))->count();
        $enough = $delivered + $failed >= (int) config('connect.directory_min_orders', 20);

        $hours = null;
        if ($enough) {
            $durations = (clone $recent)->where('status', OrderStatus::Delivered->value)->whereNotNull('delivered_at')
                ->latest('delivered_at')->limit(2000)->get(['created_at', 'delivered_at'])
                ->map(fn (Order $o) => $o->created_at->diffInMinutes($o->delivered_at) / 60);
            $hours = $durations->isEmpty() ? null : round($durations->avg(), 1);
        }

        return [
            'deliveries_90d' => $delivered,
            'success_rate' => $enough ? round($delivered / ($delivered + $failed), 2) : null,
            'average_delivery_hours' => $hours,
        ];
    }
}
