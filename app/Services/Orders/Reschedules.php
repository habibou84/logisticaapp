<?php

namespace App\Services\Orders;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\WhatsAppTemplate;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\RescheduleRequest;
use App\Models\User;
use App\Notifications\OrderAlert;
use App\Services\Messaging\Messenger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Colis pas ramassés à leur date : demande de nouvelle date au client (message du soir ou dispatch), réponse
 * (WhatsApp, page de réponse, Jibiat Marchand), report ou annulation, relance et décision du dispatch.
 *
 * Tant que la date d'un colis en retard n'est pas actualisée, il est caché chez les livreurs et hors de
 * l'assignation (Order::scopeToReschedule).
 */
class Reschedules
{
    /** Délai avant WhatsApp quand le client a Jibiat Marchand (réglage « Jibiat Marchand d'abord ») */
    public const APP_FIRST_MINUTES = 30;

    /** Heure du rappel aux clients qui n'ont pas répondu */
    public const REMINDER_TIME = '08:00';

    public const CHANNELS = ['app_first', 'whatsapp'];

    // Nouvelle date : au plus 14 jours plus tard
    public const MAX_DAYS = 14;

    // Motifs d'annulation donnés par le client
    public const CANCEL_REASONS = [
        'customer_cancelled' => 'Le destinataire a annulé sa commande',
        'out_of_stock' => 'Produit en rupture',
        'order_mistake' => 'Erreur dans la course',
        'self_delivery' => 'Je livre moi-même',
        'other' => 'Autre',
    ];

    public const NO_ANSWER = ['remind', 'auto_next_day'];

    public function __construct(
        private readonly Messenger $messenger,
        private readonly OrderService $orders,
        private readonly OrderWorkflow $workflow,
        private readonly OrderJournal $journal,
    ) {}

    public static function today(Company $company): string
    {
        return CarbonImmutable::now($company->timezone ?: config('app.timezone'))->toDateString();
    }

    public static function maxDate(Company $company): string
    {
        return CarbonImmutable::parse(self::today($company))->addDays(self::MAX_DAYS)->toDateString();
    }

    /** Courses à reporter de l'entreprise (date passée, pas encore ramassées) */
    public function toReschedule(Company $company): Builder
    {
        return Order::withoutGlobalScopes()->where('company_id', $company->id)->toReschedule(self::today($company));
    }

    /**
     * Demande une nouvelle date au client pour ces courses (une seule demande, numérotée dans cet ordre).
     *
     * @param  Collection<int, Order>  $orders
     */
    public function request(Company $company, Merchant $merchant, Collection $orders, string $origin, ?User $actor = null, ?string $note = null, ?string $missedDay = null): RescheduleRequest
    {
        $orders = $orders->filter(fn (Order $o) => $o->merchant_id === $merchant->id && in_array($o->status, Order::NOT_PICKED_STATUSES, true))
            ->sortBy(fn (Order $o) => [($o->delivery_scheduled_date ?? $o->created_at)->toDateString(), $o->id])->values();
        if ($orders->isEmpty()) {
            throw new BusinessRuleException('Aucun colis à reporter pour cet client.', 'merchant_id');
        }

        $token = RescheduleRequest::newToken();
        $request = DB::transaction(function () use ($company, $merchant, $orders, $origin, $actor, $note, $missedDay, $token) {
            $previous = $orders->pluck('reschedule_request_id')->filter()->unique();
            $request = RescheduleRequest::create([
                'company_id' => $company->id,
                'merchant_id' => $merchant->id,
                'token_hash' => RescheduleRequest::hashToken($token),
                'token' => $token,
                'order_ids' => $orders->pluck('id')->all(),
                'missed_day' => $missedDay ?? $orders->map(fn (Order $o) => ($o->delivery_scheduled_date ?? $o->created_at)->toDateString())->min(),
                'origin' => $origin,
                'note' => $note,
                'created_by' => $actor?->id,
                'expires_at' => now()->addDays(RescheduleRequest::TTL_DAYS),
            ]);
            Order::withoutGlobalScopes()->whereKey($orders->pluck('id'))->update(['reschedule_request_id' => $request->id]);
            $previous->each(fn ($id) => self::closeIfEmpty($id));

            foreach ($orders as $order) {
                $this->journal->record($order->fresh(), $actor, OrderEventType::Note, [
                    'note' => $origin === RescheduleRequest::EVENING ? 'Pas ramassé aujourd\'hui : nouvelle date demandée à l\'client' : 'Nouvelle date demandée à l\'client',
                    'meta' => ['reschedule' => 'requested'],
                ]);
            }

            return $request;
        });

        // E-commerçant sur Jibiat Marchand : il voit la demande dans l'application, WhatsApp seulement sans réponse
        if ($company->reschedule_channel !== 'app_first' || ! $this->usesApp($merchant)) {
            $this->sendWhatsApp($request);
        }

        return $request;
    }

    /**
     * Client qui verrait la demande dans son application Jibiat Marchand. Pas encore : il faut le contrat 1.10 du
     * socle (champ « reschedule »), pas repris ici ; le message WhatsApp part donc tout de suite.
     */
    public function usesApp(Merchant $merchant): bool
    {
        return false;
    }

    /**
     * Demande de nouvelle date en cours pour la course (contrat 1.10, champ « reschedule »), ou null.
     *
     * @return array{status: string, missed_day: string, requested_at: mixed, max_date: string, answer_url: string|null}|null
     */
    public static function describe(Order $order): ?array
    {
        if ($order->reschedule_request_id === null) {
            return null;
        }
        $request = $order->relationLoaded('rescheduleRequest') ? $order->rescheduleRequest
            : RescheduleRequest::withoutGlobalScopes()->find($order->reschedule_request_id);
        if ($request === null || ! $request->isOpen()) {
            return null;
        }
        $company = Company::find($order->company_id);

        return [
            'status' => 'pending',
            'missed_day' => $request->missed_day->toDateString(),
            'requested_at' => $request->created_at,
            'max_date' => self::maxDate($company),
            'answer_url' => self::link($request),
        ];
    }

    /** Lien de la page de réponse */
    public static function link(RescheduleRequest $request): string
    {
        $company = Company::find($request->company_id);

        return $company->url('/reporter/'.$request->token);
    }

    /**
     * Courses encore à reporter dans la demande, dans l'ordre des numéros (numéro => course).
     *
     * @return Collection<int, Order>
     */
    public function pendingOrders(RescheduleRequest $request): Collection
    {
        $orders = Order::withoutGlobalScopes()->where('reschedule_request_id', $request->id)
            ->with(['deliveryZone:id,name'])->get()->keyBy('id');

        return collect($request->order_ids)->mapWithKeys(fn ($id, $i) => [$i + 1 => $orders[$id] ?? null])->filter();
    }

    /** « 1) LV-… Awa K. (Yopougon) ; 2) … » */
    public function lines(RescheduleRequest $request, string $separator = ' ; '): string
    {
        return $this->pendingOrders($request)
            ->map(fn (Order $o, int $n) => "{$n}) {$o->tracking_code} ".trim(($o->recipient_name ?? '').($o->deliveryZone ? " ({$o->deliveryZone->name})" : '')))
            ->implode($separator);
    }

    public function sendWhatsApp(RescheduleRequest $request): void
    {
        $merchant = Merchant::withoutGlobalScopes()->find($request->merchant_id);
        $count = $this->pendingOrders($request)->count();
        if ($merchant === null || $count === 0) {
            return;
        }

        $company = Company::find($request->company_id);
        $day = $request->missed_day->toDateString() === self::today($company) ? 'aujourd\'hui' : 'le '.self::shortDay($request->missed_day->toDateString());
        $this->messenger->whatsapp($request->company_id, $merchant->messagingPhone(), 'merchant', WhatsAppTemplate::RescheduleRequest, [
            $merchant->business_name, $count, $day, mb_strimwidth($this->lines($request), 0, 900, '…'), self::link($request),
        ], ['event' => 'reschedule', 'merchant_id' => $merchant->id]);
        $request->forceFill(['whatsapp_sent_at' => now()])->save();
    }

    public function remind(RescheduleRequest $request): void
    {
        $merchant = Merchant::withoutGlobalScopes()->find($request->merchant_id);
        $count = $this->pendingOrders($request)->count();
        if ($merchant !== null && $count > 0) {
            $this->messenger->whatsapp($request->company_id, $merchant->messagingPhone(), 'merchant', WhatsAppTemplate::RescheduleReminder, [
                $merchant->business_name, $count, self::link($request),
            ], ['event' => 'reschedule', 'merchant_id' => $merchant->id]);
        }
        $request->forceFill(['reminded_at' => now(), 'whatsapp_sent_at' => $request->whatsapp_sent_at ?? now()])->save();
    }

    /**
     * Vérifie les choix de le client : une date entre aujourd'hui et la limite, ou une annulation avec un motif.
     *
     * @param  array<int, array{date?: string, cancel?: string, note?: string|null}>  $plan  course => choix
     */
    public function validatePlan(Company $company, array $plan): void
    {
        $today = self::today($company);
        $max = self::maxDate($company);
        foreach ($plan as $choice) {
            if (isset($choice['date']) && ($choice['date'] < $today || $choice['date'] > $max)) {
                throw new BusinessRuleException('Choisissez une date entre aujourd\'hui et le '.self::shortDay($max).'.', 'date');
            }
            if (isset($choice['cancel']) && ! array_key_exists($choice['cancel'], self::CANCEL_REASONS)) {
                throw new BusinessRuleException('Motif d\'annulation inconnu.', 'cancel');
            }
            if (! isset($choice['date']) && ! isset($choice['cancel'])) {
                throw new BusinessRuleException('Choisissez une date ou l\'annulation pour chaque colis.', 'date');
            }
        }
    }

    /**
     * Réponse de le client : reporte ou annule chaque course choisie.
     *
     * @param  array<int, array{date?: string, cancel?: string, note?: string|null}>  $plan  course => choix
     * @return array{rescheduled: list<array{code: string, date: string}>, cancelled: list<string>, skipped: list<array{code: string, why: string}>}
     */
    public function answer(RescheduleRequest $request, array $plan, string $via, ?User $actor = null): array
    {
        $company = Company::find($request->company_id);
        $this->validatePlan($company, $plan);
        $actor ??= $this->merchantActor($request->merchant_id);
        if ($actor === null) {
            throw new BusinessRuleException('Aucun compte actif pour cet client : contactez l\'agence.', 'merchant_id');
        }

        $result = ['rescheduled' => [], 'cancelled' => [], 'skipped' => []];
        foreach ($plan as $orderId => $choice) {
            $order = Order::withoutGlobalScopes()->where('company_id', $request->company_id)->where('merchant_id', $request->merchant_id)->find($orderId);
            if ($order === null || ! in_array((int) $orderId, array_map('intval', $request->order_ids), true)) {
                continue;
            }
            if (! in_array($order->status, Order::NOT_PICKED_STATUSES, true)) {
                $result['skipped'][] = ['code' => $order->tracking_code, 'why' => $order->status->isFinal() ? 'déjà '.mb_strtolower($order->status->label()) : 'déjà ramassé'];
                $this->detach($order);

                continue;
            }

            if (isset($choice['cancel'])) {
                $this->workflow->transition($actor, $order, OrderStatus::Cancelled, ['cancel_reason' => self::CANCEL_REASONS[$choice['cancel']].(filled($choice['note'] ?? null) ? ' : '.$choice['note'] : '')]);
                $this->detach($order->fresh());
                $result['cancelled'][] = $order->tracking_code;

                continue;
            }

            $this->setDate($order, $choice['date'], $actor, 'Nouvelle date choisie par le client ('.self::VIA[$via].')');
            $result['rescheduled'][] = ['code' => $order->tracking_code, 'date' => $choice['date']];
        }

        self::markAnswered($request->id, $via);

        return $result;
    }

    /**
     * Nouvelle date choisie par le client pour une course (Jibiat Marchand, application), avec ou sans demande.
     */
    public function chooseDate(Order $order, string $date, User $actor, string $via): Order
    {
        $this->validatePlan(Company::find($order->company_id), [['date' => $date]]);
        if (! in_array($order->status, Order::NOT_PICKED_STATUSES, true)) {
            throw new BusinessRuleException('Ce colis a déjà été ramassé : sa date se change avec une modification de la course.', 'date');
        }
        $requestId = $order->reschedule_request_id;
        $this->setDate($order, $date, $actor, 'Nouvelle date choisie par le client ('.self::VIA[$via].')');
        if ($requestId !== null) {
            self::markAnswered($requestId, $via);
        }

        return $order->fresh();
    }

    /** Demande dont tous les colis ont une nouvelle date (ou sont annulés) : répondue */
    private static function markAnswered(int $requestId, string $via): void
    {
        RescheduleRequest::withoutGlobalScopes()->whereKey($requestId)->whereIn('status', [RescheduleRequest::PENDING, RescheduleRequest::CLOSED])
            ->whereNull('answered_at')->whereDoesntHave('orders')
            ->update(['status' => RescheduleRequest::ANSWERED, 'answered_at' => now(), 'answered_via' => $via]);
    }

    public const VIA = ['whatsapp' => 'WhatsApp', 'page' => 'page de réponse', 'app' => 'Jibiat Marchand', 'api' => 'Jibiat Marchand', 'dispatch' => 'agence', 'auto' => 'automatique'];

    /**
     * Nouvelle date donnée par l'agence (« Choisir la date », « Tout livrer aujourd'hui ») ou automatiquement : un
     * message à chaque client avec les nouvelles dates.
     *
     * @param  Collection<int, Order>  $orders
     */
    public function setDates(Collection $orders, string $date, User $actor, string $via = 'dispatch'): int
    {
        $company = Company::find($actor->company_id ?? $orders->first()?->company_id);
        $this->validatePlan($company, [['date' => $date]]);

        $done = collect();
        foreach ($orders as $order) {
            if (! in_array($order->status, Order::NOT_PICKED_STATUSES, true)) {
                continue;
            }
            $requestId = $order->reschedule_request_id;
            $this->setDate($order, $date, $actor, $via === 'auto' ? 'Pas de réponse de l\'client : livraison reportée au '.self::shortDay($date) : 'Nouvelle date donnée par l\'agence');
            if ($requestId) {
                self::markAnswered($requestId, $via);
            }
            $done->push($order);
        }

        foreach ($done->groupBy('merchant_id') as $list) {
            $merchant = Merchant::withoutGlobalScopes()->find($list->first()->merchant_id);
            if ($merchant) {
                $this->messenger->whatsapp($merchant->company_id, $merchant->messagingPhone(), 'merchant', WhatsAppTemplate::RescheduleDone, [
                    $merchant->business_name, $list->count(), $list->map(fn (Order $o) => "{$o->tracking_code} le ".self::shortDay($date))->implode(' ; '),
                ], ['event' => 'reschedule', 'merchant_id' => $merchant->id]);
            }
        }

        return $done->count();
    }

    /** Nouvelle date d'une course (journal, livreur et client prévenus) ; la demande en cours est réglée */
    private function setDate(Order $order, string $date, User $actor, string $note): void
    {
        $current = ($order->delivery_scheduled_date ?? $order->created_at)->toDateString();
        if ($current !== $date) {
            $this->orders->update($actor, $order, ['delivery_scheduled_date' => $date]);
        }
        $this->detach($order->fresh());
        $this->journal->record($order->fresh(), $actor, OrderEventType::Note, ['note' => $note, 'meta' => ['reschedule' => 'answered']]);
    }

    /** Détache la course de sa demande (et clôt la demande si plus rien n'y reste) */
    public function detach(Order $order): void
    {
        $requestId = $order->reschedule_request_id;
        if ($requestId === null) {
            return;
        }
        $order->forceFill(['reschedule_request_id' => null])->saveQuietly();
        self::closeIfEmpty($requestId);
    }

    public static function closeIfEmpty(int $requestId): void
    {
        RescheduleRequest::withoutGlobalScopes()->whereKey($requestId)->where('status', RescheduleRequest::PENDING)
            ->whereDoesntHave('orders')->update(['status' => RescheduleRequest::CLOSED]);
    }

    private function merchantActor(int $merchantId): ?User
    {
        return User::withoutGlobalScopes()->where('merchant_id', $merchantId)->where('status', 'active')
            ->orderByRaw('CASE WHEN id IN (SELECT model_id FROM model_has_roles mr JOIN roles r ON r.id = mr.role_id WHERE r.name = ?) THEN 0 ELSE 1 END', [Role::MerchantOwner->value])
            ->orderBy('id')->first();
    }

    /** « ven. 6 nov. » */
    public static function shortDay(string $day): string
    {
        return CarbonImmutable::parse($day)->locale('fr')->isoFormat('ddd D MMM');
    }

    // ───────────── Tâches automatiques (chaque minute) ─────────────

    /**
     * Message du soir, WhatsApp des clients de Jibiat Marchand sans réponse, rappel du matin et report
     * automatique sans réponse.
     *
     * @return array{evening: int, whatsapp: int, reminded: int, auto: int}
     */
    public function tick(): array
    {
        $stats = ['evening' => 0, 'whatsapp' => 0, 'reminded' => 0, 'auto' => 0];

        Company::query()->where('status', 'active')->each(function (Company $company) use (&$stats) {
            try {
                $now = CarbonImmutable::now($company->timezone ?: config('app.timezone'));
                $today = $now->toDateString();

                if ($company->reschedule_evening && $now->format('H:i') >= $company->reschedule_evening_time
                    && $company->reschedule_evening_sent_on?->toDateString() !== $today) {
                    $company->forceFill(['reschedule_evening_sent_on' => $today])->save();
                    $stats['evening'] += $this->evening($company);
                }

                // Jibiat Marchand d'abord : WhatsApp après le délai si la demande est toujours sans réponse
                RescheduleRequest::withoutGlobalScopes()->where('company_id', $company->id)->open()->whereNull('whatsapp_sent_at')
                    ->where('created_at', '<=', now()->subMinutes(self::APP_FIRST_MINUTES))->get()
                    ->each(function (RescheduleRequest $r) use (&$stats) {
                        $this->sendWhatsApp($r);
                        $stats['whatsapp']++;
                    });

                if ($now->format('H:i') >= self::REMINDER_TIME) {
                    $stats[$company->reschedule_no_answer === 'auto_next_day' ? 'auto' : 'reminded'] += $this->morning($company, $today);
                }
            } catch (Throwable $e) {
                report($e);
            }
        });

        return $stats;
    }

    /** Colis du jour pas ramassés : une demande par client, et le bilan à l'équipe */
    public function evening(Company $company): int
    {
        $today = self::today($company);
        $orders = Order::withoutGlobalScopes()->where('company_id', $company->id)
            ->whereIn('status', OrderStatus::values(Order::NOT_PICKED_STATUSES))
            ->whereNull('pickup_hub_id')->where('return_requested', false)
            ->whereRaw(Order::DELIVERY_DAY_SQL.' = ?', [$today])
            ->where(fn ($q) => $q->whereNull('reschedule_request_id')
                ->orWhereHas('rescheduleRequest', fn ($r) => $r->where('status', '!=', RescheduleRequest::PENDING)))
            ->get();

        $sent = 0;
        foreach ($orders->groupBy('merchant_id') as $merchantId => $list) {
            $merchant = Merchant::withoutGlobalScopes()->find($merchantId);
            if ($merchant === null) {
                continue;
            }
            $this->request($company, $merchant, $list, RescheduleRequest::EVENING, null, null, $today);
            $sent++;
        }

        if ($sent > 0) {
            $staff = User::forCompany($company->id)->where('status', 'active')->role([Role::Admin->value, Role::Dispatcher->value])->get();
            Notification::send($staff, new OrderAlert($orders->first(), 'reschedule_evening',
                "📅 {$orders->count()} colis pas ramassés aujourd'hui",
                "Nouvelle date demandée à {$sent} client(s). Les réponses arrivent dans « À reporter ».",
                ['url' => '/admin/courses/a-reporter']));
        }

        return $sent;
    }

    /** Le matin : rappel aux clients sans réponse, ou report automatique à aujourd'hui (réglage) */
    private function morning(Company $company, string $today): int
    {
        $requests = RescheduleRequest::withoutGlobalScopes()->where('company_id', $company->id)->open()
            ->whereDate('created_at', '<', $today)->whereNull('reminded_at')->get();

        $count = 0;
        foreach ($requests as $request) {
            if ($company->reschedule_no_answer === 'auto_next_day') {
                $actor = User::forCompany($company->id)->where('status', 'active')->role(Role::Admin->value)->first();
                $orders = $this->pendingOrders($request);
                if ($actor && $orders->isNotEmpty()) {
                    $this->setDates($orders->values(), $today, $actor, 'auto');
                }
                $request->forceFill(['reminded_at' => now()])->save();
            } else {
                $this->remind($request);
            }
            $count++;
        }

        return $count;
    }
}
