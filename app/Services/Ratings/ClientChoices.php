<?php

namespace App\Services\Ratings;

use App\Models\Company;
use App\Models\Courier;
use App\Models\MerchantCourierPreference;
use App\Models\Order;
use App\Services\Assignment\CourierPlanning;
use Carbon\CarbonImmutable;

/**
 * Livreurs préférés ou écartés par les clients (si l'entreprise le permet) : l'assignation automatique
 * ne donne plus ses courses à un livreur écarté, et donne la priorité au préféré quand il est disponible.
 */
class ClientChoices
{
    public function __construct(
        private readonly RatingSettings $settings,
        private readonly CourierPlanning $planning,
    ) {}

    /**
     * @return list<int>
     */
    public function excluded(Order $order): array
    {
        if ($order->merchant_id === null || ! $this->enabled($order)) {
            return [];
        }

        return MerchantCourierPreference::withoutGlobalScopes()->where('merchant_id', $order->merchant_id)
            ->where('kind', MerchantCourierPreference::EXCLUDED)->pluck('courier_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Livreur préféré du client, s'il est en service, présent et pas écarté pour cette course.
     *
     * @param  list<int>  $exclude
     */
    public function preferred(Order $order, array $exclude = []): ?Courier
    {
        if ($order->merchant_id === null || ! $this->enabled($order)) {
            return null;
        }
        $id = MerchantCourierPreference::withoutGlobalScopes()->where('merchant_id', $order->merchant_id)
            ->where('kind', MerchantCourierPreference::PREFERRED)->value('courier_id');
        $courier = $id ? Courier::withoutGlobalScopes()->with('user')->find($id) : null;
        if ($courier === null || in_array($courier->id, $exclude, true)) {
            return null;
        }
        $company = Company::query()->find($order->company_id);
        $today = CarbonImmutable::now($company?->timezone ?: config('app.timezone'))->toDateString();

        return $this->planning->available($courier, $this->planning->absentOn($order->company_id, $today)) ? $courier : null;
    }

    private function enabled(Order $order): bool
    {
        return (bool) $this->settings->for(Company::query()->find($order->company_id))['client_choice'];
    }
}
