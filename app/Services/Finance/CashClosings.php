<?php

namespace App\Services\Finance;

use App\Enums\PaymentMethod;
use App\Enums\PayoutStatus;
use App\Enums\Role;
use App\Enums\WhatsAppTemplate;
use App\Exceptions\BusinessRuleException;
use App\Models\CashClosing;
use App\Models\CashCollection;
use App\Models\Company;
use App\Models\CourierAdvance;
use App\Models\CourierPayout;
use App\Models\CourierRemittance;
use App\Models\MerchantPayout;
use App\Models\User;
use App\Notifications\CashClosed;
use App\Services\Messaging\Messenger;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Clôture de la caisse : ce qui est entré et sorti depuis la clôture précédente, par moyen de paiement, comparé à ce
 * que la caisse compte. Une clôture par jour ; un mouvement saisi après la clôture passe sur la suivante.
 *
 * Entrées : versements des livreurs (espèces), encaissements reçus directement sur un compte de l'entreprise.
 * Sorties : paiements aux clients, fiches de paie (sauf celles gardées sur l'encaissé), avances aux livreurs,
 * remboursements de frais aux livreurs (versement négatif).
 */
class CashClosings
{
    /** Moyens toujours affichés, même sans mouvement */
    public const ALWAYS = [PaymentMethod::Cash, PaymentMethod::Wave, PaymentMethod::OrangeMoney];

    public function __construct(private CourierCash $cash, private CashJournal $journal, private Messenger $messenger) {}

    public function last(int $companyId): ?CashClosing
    {
        return CashClosing::withoutGlobalScopes()->where('company_id', $companyId)->latest('closed_at')->first();
    }

    public function today(int $companyId): ?CashClosing
    {
        return CashClosing::withoutGlobalScopes()->where('company_id', $companyId)->whereDate('day', today())->first();
    }

    /**
     * Mouvements depuis la clôture précédente (à défaut, depuis le début du jour), par moyen.
     *
     * @return array{period_start: Carbon, lines: list<array{method: string, label: string, start: ?int, in: int, out: int, expected: ?int}>, total_in: int, total_out: int, with_couriers: int}
     */
    public function figures(int $companyId): array
    {
        $last = $this->last($companyId);
        $from = $last?->closed_at ?? today();
        $scoped = fn (string $model) => $model::withoutGlobalScopes()->where('company_id', $companyId);
        $in = $out = [];
        $add = function (array &$bucket, string $method, int $amount) {
            $bucket[$method] = ($bucket[$method] ?? 0) + $amount;
        };

        $remittances = $scoped(CourierRemittance::class)->whereNull('cancelled_at')->where('received_at', '>=', $from)->pluck('amount_received');
        foreach ($remittances as $amount) {
            $amount >= 0 ? $add($in, PaymentMethod::Cash->value, (int) $amount) : $add($out, PaymentMethod::Cash->value, -(int) $amount);
        }

        // Reçu directement sur le compte de l'entreprise : compté le jour où la caisse l'a confirmé
        $direct = $scoped(CashCollection::class)->where('received_by_company', true)->whereNull('legacy_cash_baseline_id')
            ->whereNotNull('company_confirmed_at')->where('company_confirmed_at', '>=', $from)->groupBy('method')->selectRaw('method, SUM(amount_collected) AS total')->pluck('total', 'method');
        foreach ($direct as $method => $total) {
            $add($in, $method instanceof PaymentMethod ? $method->value : (string) $method, (int) $total);
        }

        $merchantPaid = $scoped(MerchantPayout::class)->where('status', PayoutStatus::Paid->value)->where('paid_at', '>=', $from)->get(['method', 'net_amount']);
        foreach ($merchantPaid as $payout) {
            $method = $payout->method?->value ?? PaymentMethod::Cash->value;
            // Paiement négatif : le client a réglé ce qu'il devait
            $payout->net_amount >= 0 ? $add($out, $method, $payout->net_amount) : $add($in, $method, -$payout->net_amount);
        }

        $payslips = $scoped(CourierPayout::class)->where('status', PayoutStatus::Paid->value)->where('compensated', false)->where('paid_at', '>=', $from)->get(['method', 'amount']);
        foreach ($payslips as $payout) {
            $add($out, $payout->method?->value ?? PaymentMethod::Cash->value, max(0, $payout->amount));
        }

        $advances = (int) $scoped(CourierAdvance::class)->whereNull('cancelled_at')->whereNull('courier_payout_id')->where('amount', '>', 0)
            ->where('given_at', '>=', $from)->sum('amount');
        if ($advances > 0) {
            $add($out, PaymentMethod::Cash->value, $advances);
        }

        $previous = collect($last?->lines ?? [])->keyBy('method');
        $methods = collect(self::ALWAYS)->map->value->merge(array_keys($in))->merge(array_keys($out))->unique()->values();
        $lines = $methods->map(function (string $method) use ($in, $out, $previous, $last) {
            // Début : le fond laissé (espèces) ou le solde compté à la clôture précédente
            $start = $last === null ? null : ($method === PaymentMethod::Cash->value ? $last->float_kept : ($previous[$method]['counted'] ?? null));
            $moved = ($in[$method] ?? 0) - ($out[$method] ?? 0);

            return [
                'method' => $method,
                'label' => PaymentMethod::tryFrom($method)?->label() ?? $method,
                'start' => $start,
                'in' => $in[$method] ?? 0,
                'out' => $out[$method] ?? 0,
                'expected' => $start === null ? null : $start + $moved,
            ];
        })->all();

        $withCouriers = collect($this->cash->balances($companyId))->sum(fn ($b) => max(0, $b['due']));

        return [
            'period_start' => Carbon::parse($from),
            'lines' => $lines,
            'total_in' => array_sum($in),
            'total_out' => array_sum($out),
            'with_couriers' => (int) $withCouriers,
        ];
    }

    /**
     * Clôture la journée avec les montants comptés (par moyen ; espèces obligatoires).
     *
     * @param  array<string, int|null>  $counted
     */
    public function close(User $actor, Company $company, array $counted, ?string $explanation, int $floatKept): CashClosing
    {
        return DB::transaction(function () use ($actor, $company, $counted, $explanation, $floatKept) {
            Company::query()->whereKey($company->id)->lockForUpdate()->first();
            if ($this->today($company->id)) {
                throw new BusinessRuleException('La caisse est déjà clôturée aujourd\'hui. Ce qui arrive maintenant passera sur la clôture de demain.');
            }
            if (! isset($counted[PaymentMethod::Cash->value])) {
                throw new BusinessRuleException('Comptez les espèces de la caisse.', 'counted.cash');
            }

            $figures = $this->figures($company->id);
            $lines = array_map(function (array $line) use ($counted) {
                $value = $counted[$line['method']] ?? null;
                $line['counted'] = $value === null ? null : (int) $value;
                // Premier comptage d'un moyen (aucun début connu) : il sert de départ, pas d'écart
                $line['difference'] = $line['counted'] === null || $line['expected'] === null ? null : $line['counted'] - $line['expected'];

                return $line;
            }, $figures['lines']);

            $difference = (int) collect($lines)->sum(fn ($l) => $l['difference'] ?? 0);
            $gap = collect($lines)->contains(fn ($l) => ($l['difference'] ?? 0) !== 0);
            if ($gap && blank($explanation)) {
                throw new BusinessRuleException('Il y a un écart : expliquez-le avant de clôturer.', 'explanation');
            }

            $cashCounted = (int) $counted[PaymentMethod::Cash->value];
            if ($floatKept < 0 || $floatKept > $cashCounted) {
                throw new BusinessRuleException('Le fond laissé pour demain doit être entre 0 et les espèces comptées.', 'float_kept');
            }

            $closing = CashClosing::create([
                'company_id' => $company->id,
                'day' => today(),
                'period_start' => $figures['period_start'],
                'lines' => $lines,
                'total_in' => $figures['total_in'],
                'total_out' => $figures['total_out'],
                'difference' => $difference,
                'explanation' => $gap ? $explanation : null,
                'float_kept' => $floatKept,
                'deposited' => $cashCounted - $floatKept,
                'with_couriers' => $figures['with_couriers'],
                'closed_by' => $actor->id,
                'closed_at' => now(),
            ]);

            $this->journal->record($actor, $company->id, 'cash_closed',
                'Caisse du '.$closing->day->format('d/m').' clôturée'.($gap ? ' · écart '.Money::format($difference).' · « '.$explanation.' »' : ' sans écart'),
                ['amount' => $difference]);

            DB::afterCommit(fn () => $this->announce($company, $closing->fresh('closer')));

            return $closing;
        });
    }

    /** Résumé aux administrateurs : cloche, et WhatsApp si l'entreprise le veut */
    private function announce(Company $company, CashClosing $closing): void
    {
        $admins = User::query()->where('company_id', $company->id)->whereNull('merchant_id')
            ->where('status', 'active')->role(Role::Admin->value)->get();
        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new CashClosed($closing));

        if (! $company->cash_close_summary) {
            return;
        }

        $cash = collect($closing->lines)->firstWhere('method', PaymentMethod::Cash->value);
        $gap = $closing->explanation
            ? 'Écart : '.Money::format($closing->difference).' ('.$closing->explanation.').'
            : 'Aucun écart.';

        foreach ($admins as $admin) {
            $this->messenger->whatsapp($company->id, $admin->phone, 'staff', WhatsAppTemplate::CashClosing, [
                strtok($admin->name, ' ') ?: $admin->name,
                $company->name,
                $closing->day->format('d/m'),
                $closing->closer?->name ?? '—',
                Money::format($closing->total_in),
                Money::format($closing->total_out),
                Money::format((int) ($cash['counted'] ?? 0)),
                $cash['expected'] === null ? 'premier comptage' : Money::format($cash['expected']),
                $gap,
                Money::format($closing->with_couriers),
            ], ['event' => 'cash.closed']);
        }
    }

    /**
     * Rappel du soir aux responsables de la caisse quand la journée n'est pas clôturée. Renvoie le nombre
     * d'entreprises prévenues.
     */
    public function remindDue(): int
    {
        $sent = 0;
        Company::query()->whereNotNull('cash_close_reminder_at')
            ->where(fn ($q) => $q->whereNull('cash_close_reminded_on')->orWhereDate('cash_close_reminded_on', '<', today()))
            ->each(function (Company $company) use (&$sent) {
                if (now()->format('H:i') < substr((string) $company->cash_close_reminder_at, 0, 5) || $this->today($company->id)) {
                    return;
                }
                $company->forceFill(['cash_close_reminded_on' => today()])->save();

                $figures = $this->figures($company->id);
                if ($figures['total_in'] === 0 && $figures['total_out'] === 0) {
                    return;
                }

                $staff = User::query()->where('company_id', $company->id)->whereNull('merchant_id')->where('status', 'active')
                    ->role([Role::Admin->value, Role::Cashier->value])->get();
                Notification::send($staff, new CashClosed(null, $figures['total_in'], $figures['total_out']));
                $sent++;
            });

        return $sent;
    }
}
