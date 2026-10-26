<?php

namespace App\Services\Reports;

use App\Enums\Activity;
use App\Enums\ClientInvoiceStatus;
use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Models\ClientCreditNote;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\Company;
use App\Models\MerchantLedgerEntry;
use App\Models\MerchantPayout;
use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * Chiffre d'affaires mois par mois, séparé par activité :
 *  - e-commerce : frais de livraison, de retour et de stockage retenus aux clients (facturé à la livraison) ;
 *    encaissé : ceux des reversements payés ;
 *  - distribution, MAD : factures émises du mois (facturé) et paiements reçus dans le mois (encaissé).
 * Avec le nombre de courses créées dans le mois (hors courses annulées ou refusées).
 */
class Revenue
{
    private const ECOMMERCE_FEES = [LedgerEntryType::DeliveryFee, LedgerEntryType::ReturnFee, LedgerEntryType::StorageFee];

    /**
     * @param  list<Activity>  $activities  activités visibles (périmètre)
     * @return array{months: list<array<string, mixed>>, totals: array<string, array<string, int>>}
     */
    public function monthly(Company $company, CarbonImmutable $from, CarbonImmutable $to, array $activities): array
    {
        $tz = $company->timezone ?: config('app.timezone');
        $months = [];
        for ($m = $from->startOfMonth(); $m <= $to; $m = $m->addMonth()) {
            $start = CarbonImmutable::create($m->year, $m->month, 1, 0, 0, 0, $tz);
            $end = $start->addMonth();
            $row = ['month' => $start->format('Y-m'), 'label' => ucfirst($start->locale('fr')->translatedFormat('F Y')), 'activities' => []];

            foreach ($activities as $activity) {
                $row['activities'][$activity->value] = [
                    'orders' => $this->orders($company, $activity, $start, $end),
                    ...($activity === Activity::Ecommerce ? $this->ecommerce($company, $start, $end) : $this->invoiced($company, $activity, $start, $end)),
                ];
            }
            $row['total'] = [
                'billed' => array_sum(array_column($row['activities'], 'billed')),
                'collected' => array_sum(array_column($row['activities'], 'collected')),
                'orders' => array_sum(array_column($row['activities'], 'orders')),
            ];
            $months[] = $row;
        }

        $totals = [];
        foreach ([...array_map(fn (Activity $a) => $a->value, $activities), 'total'] as $key) {
            foreach (['billed', 'collected', 'orders'] as $field) {
                $totals[$key][$field] = array_sum(array_map(fn ($r) => $key === 'total' ? $r['total'][$field] : $r['activities'][$key][$field], $months));
            }
        }

        return ['months' => $months, 'totals' => $totals];
    }

    private function orders(Company $company, Activity $activity, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return Order::withoutGlobalScopes()->where('company_id', $company->id)->where('activity', $activity->value)
            ->whereNull('deleted_at')
            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Rejected->value])
            ->where('created_at', '>=', $start->utc())->where('created_at', '<', $end->utc())
            ->count();
    }

    /** @return array{billed: int, collected: int} */
    private function ecommerce(Company $company, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $types = array_map(fn (LedgerEntryType $t) => $t->value, self::ECOMMERCE_FEES);
        $fees = fn () => MerchantLedgerEntry::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('type', $types);

        $paidPayouts = MerchantPayout::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'paid')
            ->where('paid_at', '>=', $start->utc())->where('paid_at', '<', $end->utc())->select('id');

        return [
            'billed' => -(int) $fees()->where('created_at', '>=', $start->utc())->where('created_at', '<', $end->utc())->sum('amount'),
            'collected' => -(int) $fees()->whereIn('payout_id', $paidPayouts)->sum('amount'),
        ];
    }

    /** @return array{billed: int, collected: int} */
    private function invoiced(Company $company, Activity $activity, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $invoices = fn () => ClientInvoice::withoutGlobalScopes()->where('company_id', $company->id)->where('activity', $activity->value);

        return [
            // Hors taxe ; les avoirs viennent en moins le mois où ils sont faits
            'billed' => (int) $invoices()->where(fn ($q) => $q->whereIn('status', [ClientInvoiceStatus::Issued->value, ClientInvoiceStatus::Paid->value])->orWhere('credited_amount', '>', 0))
                ->whereDate('period', $start->toDateString())->sum('total_ht')
                - (int) ClientCreditNote::withoutGlobalScopes()->where('company_id', $company->id)->where('activity', $activity->value)
                    ->whereDate('issued_at', '>=', $start->toDateString())->whereDate('issued_at', '<', $end->toDateString())->sum('total_ht'),
            'collected' => (int) ClientInvoicePayment::withoutGlobalScopes()->where('company_id', $company->id)
                ->whereIn('client_invoice_id', $invoices()->select('id'))
                ->whereDate('paid_on', '>=', $start->toDateString())->whereDate('paid_on', '<', $end->toDateString())
                ->sum('amount'),
        ];
    }
}
