<?php

namespace App\Services\Orders;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Services\Messaging\OrderMessages;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Journée de livraison de l'entreprise, en mode « validation automatique avec règles » :
 * passé l'heure limite, ou quand le dispatch a fermé la journée, les courses prévues
 * aujourd'hui (ou « dès que possible ») sont validées pour le prochain jour de livraison.
 * Le ramassage, lui, se fait toujours le jour même : seule la livraison est décalée.
 */
class DeliveryDay
{
    /** Courses du jour pas encore parties en livraison : décalées quand la journée ferme */
    private const NOT_OUT_YET = [
        OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::PickupAssigned,
        OrderStatus::PickupInProgress, OrderStatus::PickedUp, OrderStatus::AtHub,
    ];

    public function __construct(
        private readonly AwaitingCourier $awaiting,
        private readonly OrderJournal $journal,
        private readonly OrderMessages $messages,
    ) {}

    public function rulesApply(Company $company): bool
    {
        return $company->validation_mode === Company::VALIDATION_RULES;
    }

    public function today(Company $company): CarbonImmutable
    {
        return CarbonImmutable::now($company->timezone ?: config('app.timezone'))->startOfDay();
    }

    /** Prochain jour de livraison (le lendemain ; les jours sans livraison viendront ensuite) */
    public function nextDay(Company $company): CarbonImmutable
    {
        return $this->today($company)->addDay();
    }

    /**
     * Raison de la fermeture du jour (null si les livraisons du jour sont ouvertes) :
     * « manual » (fermée par le dispatch) ou « cutoff » (heure limite passée).
     */
    public function closedReason(Company $company): ?string
    {
        if (! $this->rulesApply($company)) {
            return null;
        }
        if ($company->deliveries_closed_on?->toDateString() === $this->today($company)->toDateString()) {
            return 'manual';
        }

        return $this->awaiting->cutoffPassed($company) ? 'cutoff' : null;
    }

    /**
     * État de la journée pour les écrans (dispatch et application marchand).
     *
     * @return array<string, mixed>
     */
    public function status(Company $company, bool $withCount = false): array
    {
        $reason = $this->closedReason($company);

        return [
            'mode' => $company->validation_mode ?? Company::VALIDATION_MANUAL,
            'rules' => $this->rulesApply($company),
            'closed' => $reason !== null,
            'reason' => $reason,
            'message' => $reason ? $this->reasonText($company, $reason) : null,
            'cutoff_time' => $company->cutoffFor(),
            'closed_at' => $reason === 'manual' ? $company->deliveries_closed_at?->toIso8601String() : null,
            'today' => $this->today($company)->toDateString(),
            'next_day' => $this->nextDay($company)->toDateString(),
            // Courses du jour qu'une fermeture pourrait décaler (dispatch seulement)
            'today_open_count' => $withCount && $this->rulesApply($company) ? $this->todaysOpenOrders($company)->count() : null,
        ];
    }

    /** « livraisons du jour closes depuis 16:00 » / « livraisons du jour fermées par l'agence » */
    public function reasonText(Company $company, string $reason): string
    {
        return $reason === 'cutoff'
            ? "livraisons du jour closes depuis {$company->cutoffFor()}"
            : 'livraisons du jour fermées par l\'agence';
    }

    /**
     * À l'enregistrement : course prévue aujourd'hui (ou sans date) alors que la journée est
     * fermée → livraison au prochain jour. Renvoie la raison du décalage, ou null.
     */
    public function postponeIfClosed(Order $order, Company $company): ?string
    {
        $reason = $this->closedReason($company);
        if ($reason === null || ! $this->isForToday($order, $company)) {
            return null;
        }

        $order->forceFill(['delivery_scheduled_date' => $this->nextDay($company)->toDateString()])->save();

        return $this->reasonText($company, $reason);
    }

    private function isForToday(Order $order, Company $company): bool
    {
        return $order->delivery_scheduled_date === null
            || $order->delivery_scheduled_date->toDateString() <= $this->today($company)->toDateString();
    }

    /**
     * Ferme la journée. Avec $postpone, les courses du jour pas encore parties en livraison
     * passent au lendemain (celles en attente sont validées) et leur marchand est prévenu.
     *
     * @return int nombre de courses décalées
     */
    public function close(Company $company, User $actor, bool $postpone): int
    {
        $company->forceFill(['deliveries_closed_on' => $this->today($company)->toDateString(), 'deliveries_closed_at' => now()])->save();

        if (! $postpone) {
            return 0;
        }

        $next = $this->nextDay($company);
        $reason = $this->reasonText($company, 'manual');
        $count = 0;

        $this->todaysOpenOrders($company)->with('merchant')->chunkById(100, function ($orders) use ($actor, $next, $reason, &$count) {
            foreach ($orders as $order) {
                DB::transaction(function () use ($order, $actor, $next, $reason) {
                    $wasPending = $order->status === OrderStatus::Pending;
                    $order->forceFill(['delivery_scheduled_date' => $next->toDateString()]);
                    if ($wasPending) {
                        $order->forceFill(['status' => OrderStatus::Confirmed, 'confirmed_at' => now()]);
                    }
                    $order->save();

                    $note = "Livraison décalée au {$next->format('d/m')} : {$reason}";
                    // Validation : le message « validée pour le … » part avec le journal (OrderMessages)
                    $this->journal->record($order, $actor, $wasPending ? OrderEventType::StatusChanged : OrderEventType::Note, [
                        ...($wasPending ? ['from_status' => OrderStatus::Pending, 'to_status' => OrderStatus::Confirmed] : []),
                        'note' => $note,
                        'visible_to_merchant' => true,
                        'meta' => ['postponed' => $reason],
                    ]);

                    if (! $wasPending) {
                        $this->messages->confirmedForDate($order, $reason, postponed: true);
                    }
                });
                $count++;
            }
        });

        return $count;
    }

    public function reopen(Company $company): void
    {
        $company->forceFill(['deliveries_closed_on' => null, 'deliveries_closed_at' => null])->save();
    }

    /** « mardi 21/10 » */
    public static function longDate(\DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)->locale('fr')->isoFormat('dddd DD/MM');
    }

    /**
     * Courses du jour pas encore parties en livraison, hors retours.
     */
    private function todaysOpenOrders(Company $company): Builder
    {
        $today = $this->today($company)->toDateString();

        return Order::query()
            ->where('company_id', $company->id)
            ->whereIn('status', OrderStatus::values(self::NOT_OUT_YET))
            ->where('return_requested', false)
            ->where(fn (Builder $q) => $q->whereNull('delivery_scheduled_date')->orWhereDate('delivery_scheduled_date', '<=', $today));
    }
}
