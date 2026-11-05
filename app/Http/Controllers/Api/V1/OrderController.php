<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Activity;
use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreOrderRequest;
use App\Http\Requests\V1\UpdateOrderRequest;
use App\Http\Resources\V1\OrderResource;
use App\Models\Courier;
use App\Models\IncidentReason;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\Orders\AwaitingCourier;
use App\Services\Orders\OrderService;
use App\Services\Orders\PickupGroups;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public const LIST_RELATIONS = [
        'merchant', 'pickupZone', 'deliveryZone', 'lastIncidentReason', 'followUpReason', 'pickupHub', 'items',
        'pickupCourier.user', 'deliveryCourier.user', 'returnCourier.user', 'holder.user', 'merchantDecision',
        'department', 'toDepartment', 'destination',
    ];

    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Order::class);

        $request->validate([
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(OrderStatus::class)],
            // File d'attente du dispatch : à valider, à ramasser, à livrer, à retourner
            'queue' => ['nullable', Rule::in(['to_confirm', 'to_approve', 'on_hold', 'follow_up', 'to_pickup', 'to_reschedule', 'to_prepare', 'to_deliver', 'unassigned', 'to_decide', 'scheduled', 'to_return', 'incidents', 'failed', 'code_to_verify', 'internal_late', 'untreated'])],
            // Courses non traitées : celles qui n'ont pas de livreur
            'without_courier' => ['nullable', 'boolean'],
            // Courses non traitées : livreur de la livraison, à défaut du ramassage
            'lead_courier_id' => ['nullable', 'integer'],
            // File « Sans livreur » : ramassage, livraison ou les deux
            'awaiting' => ['nullable', Rule::in([AwaitingCourier::PICKUP, AwaitingCourier::DELIVERY])],
            'merchant_id' => ['nullable', 'integer'],
            // Département du client : courses qu'il envoie ou qu'il reçoit
            'department_id' => ['nullable', 'integer'],
            // Activité : e-commerce, distribution, MAD (dans le périmètre de l'utilisateur)
            'activity' => ['nullable', Rule::enum(Activity::class)],
            'hub_id' => ['nullable', 'integer'],
            'courier_id' => ['nullable', 'integer'],
            'zone_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            // Période selon le jour de création (par défaut) ou le jour de livraison prévu
            'date_basis' => ['nullable', Rule::in(['created', 'delivery'])],
            // Jour de travail de l'agence : courses du jour et retards ; files de travail limitées à ce qui est dû
            'day' => ['nullable', 'date'],
            // Files de travail : ajouter les courses à livrer après ce jour (ramassage en avance)
            'include_later' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $queue = (string) $request->input('queue', '');
        // Une période choisie l'emporte sur le jour
        $day = $request->filled('day') && ! $request->filled('from') && ! $request->filled('to') ? $request->date('day')->toDateString() : null;

        $orders = Order::query()
            ->visibleTo($request->user())
            ->with(self::LIST_RELATIONS)
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', $request->input('status')))
            ->when($queue !== '' && $queue !== 'unassigned', fn ($q) => $this->applyQueue($q, $queue, $day))
            ->when($queue === 'unassigned', fn ($q) => AwaitingCourier::scope($q, $request->input('awaiting'), $day))
            ->when($day !== null, fn ($q) => $this->applyDay($q, $queue, $day, $request->boolean('include_later')))
            // Jour choisi (courses d'avant encore dans leur règle d'abord) et non traitées : de la plus ancienne à la plus récente
            ->when(($day !== null && ($queue === '' || in_array($queue, self::WORK_QUEUES, true))) || $queue === 'untreated', fn ($q) => $q->orderByRaw(Order::DELIVERY_DAY_SQL))
            ->when($request->boolean('without_courier'), fn ($q) => $q->whereNull('pickup_courier_id')->whereNull('delivery_courier_id'))
            ->when($request->filled('lead_courier_id'), fn ($q) => $q->whereRaw('COALESCE(delivery_courier_id, pickup_courier_id) = ?', [$request->integer('lead_courier_id')]))
            // Sans livreur : de la plus ancienne attente à la plus récente
            ->when($queue === 'unassigned', fn ($q) => $q->orderBy('status_changed_at'))
            ->when($request->filled('merchant_id'), fn ($q) => $q->where('merchant_id', $request->integer('merchant_id')))
            ->when($request->filled('activity'), fn ($q) => $q->where('activity', $request->string('activity')))
            ->when($request->filled('department_id'), fn ($q) => $q->where(fn ($q) => $q->where('department_id', $request->integer('department_id'))->orWhere('to_department_id', $request->integer('department_id'))))
            // Courses d'un contrat ou d'un lot (distribution, MAD), ou une liste précise (feuille de route)
            // Étiquette pas encore imprimée
            ->when($request->input('label') === 'unprinted', fn ($q) => $q->whereNull('label_printed_at'))
            ->when($request->filled('billing_plan_id'), fn ($q) => $q->where('billing_plan_id', $request->integer('billing_plan_id')))
            ->when($request->filled('ids'), fn ($q) => $q->whereIn('id', array_map('intval', array_slice((array) $request->input('ids'), 0, 500))))
            ->when($request->filled('hub_id'), fn ($q) => $q->where('pickup_hub_id', $request->integer('hub_id')))
            ->when($request->filled('courier_id'), fn ($q) => $q->where(fn ($q) => $q
                ->where('pickup_courier_id', $request->integer('courier_id'))
                ->orWhere('delivery_courier_id', $request->integer('courier_id'))
                ->orWhere('return_courier_id', $request->integer('courier_id'))))
            ->when($request->filled('zone_id'), fn ($q) => $q->where(fn ($q) => $q
                ->where('delivery_zone_id', $request->integer('zone_id'))
                ->orWhere('pickup_zone_id', $request->integer('zone_id'))))
            ->forPeriod($request->date('from')?->toDateString(), $request->date('to')?->toDateString(), $request->input('date_basis', 'created'))
            ->when($request->filled('search'), function ($q) use ($request) {
                $raw = trim($request->string('search'));
                $term = '%'.mb_strtolower($raw).'%';
                $phone = PhoneNumber::normalize($raw);
                $q->where(fn ($q) => $q
                    ->whereRaw('LOWER(tracking_code) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(merchant_reference) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(recipient_name) LIKE ?', [$term])
                    ->orWhere('recipient_phone', 'like', $phone ? "%{$phone}%" : $term));
            })
            ->latest('id')
            ->paginate(min(max($request->integer('per_page', 30), 1), 500));

        return OrderResource::collection($orders);
    }

    /**
     * Compteurs du dispatch : courses à valider, à ramasser, à livrer, et celles qui
     * attendent un livreur au-delà du délai de l'entreprise.
     */
    /**
     * Courses non traitées (jour dépassé, pas terminées, plus dans la règle de leur activité), regroupées par jour
     * prévu, par livreur (celui de la livraison, à défaut du ramassage) ou par client : nombre, envois (plis comptés
     * un à un) et montant à encaisser.
     */
    public function untreatedGroups(Request $request): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);
        $data = $request->validate([
            'by' => ['nullable', Rule::in(['date', 'courier', 'merchant'])],
            'activity' => ['nullable', Rule::enum(Activity::class)],
        ]);
        $by = $data['by'] ?? 'date';

        $base = Order::query()->visibleTo($request->user())->untreated()
            ->when($data['activity'] ?? null, fn ($q, $activity) => $q->where('activity', $activity));
        $sums = "COUNT(*) AS n, SUM(CASE WHEN orders.activity = 'ecommerce' THEN cod_amount ELSE 0 END) AS amount, MIN(".Order::DELIVERY_DAY_SQL.') AS oldest';
        $rows = match ($by) {
            'date' => $base->selectRaw(Order::DELIVERY_DAY_SQL.' AS k, '.$sums)->groupByRaw(Order::DELIVERY_DAY_SQL)->orderByRaw(Order::DELIVERY_DAY_SQL)->get(),
            'courier' => $base->selectRaw('COALESCE(delivery_courier_id, pickup_courier_id) AS k, '.$sums)->groupByRaw('COALESCE(delivery_courier_id, pickup_courier_id)')->orderByDesc('n')->get(),
            'merchant' => $base->selectRaw('merchant_id AS k, '.$sums)->groupBy('merchant_id')->orderByDesc('n')->get(),
        };
        $names = match ($by) {
            'courier' => Courier::query()->with('user:id,name')->whereIn('id', $rows->pluck('k')->filter())->get()->mapWithKeys(fn (Courier $c) => [$c->id => $c->user?->name]),
            'merchant' => Merchant::query()->whereIn('id', $rows->pluck('k'))->pluck('business_name', 'id'),
            default => collect(),
        };
        $groups = $rows->map(fn ($r) => [
            'key' => $r->k === null ? 'none' : (string) $r->k,
            'label' => match ($by) {
                'date' => ucfirst(Carbon::parse($r->k)->locale('fr')->isoFormat('dddd D MMMM YYYY')),
                'courier' => $r->k ? ($names[$r->k] ?? 'Livreur') : 'Sans livreur',
                'merchant' => $names[$r->k] ?? 'Client',
            },
            'count' => (int) $r->n,
            'amount' => (int) $r->amount,
            'oldest' => (string) $r->oldest,
        ])->values();

        return response()->json(['data' => ['by' => $by, 'total' => $groups->sum('count'), 'groups' => $groups]]);
    }

    /**
     * Ramassages à assigner, regroupés par client et adresse : un lot s'assigne d'un coup.
     */
    public function pickupGroups(Request $request, PickupGroups $groups): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);

        $day = $request->validate(['day' => ['nullable', 'date']])['day'] ?? null;

        return response()->json(['data' => $groups->toAssign($request->user(), $day ? Carbon::parse($day)->toDateString() : null)]);
    }

    public function counts(Request $request, AwaitingCourier $awaiting): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);

        $data = $request->validate([
            'day' => ['nullable', 'date'],
            // Tableau de bord : une seule activité (les compteurs d'attente et l'heure limite restent ceux de l'entreprise)
            'activity' => ['nullable', Rule::enum(Activity::class)],
        ]);
        $activity = $data['activity'] ?? null;
        $base = fn (): Builder => Order::query()->when($activity, fn ($q) => $q->where('activity', $activity));
        $count = fn (string $queue) => $this->applyQueue($base(), $queue)->count();
        $day = $data['day'] ?? null;

        return response()->json(['data' => [
            'to_confirm' => $count('to_confirm'),
            'to_pickup' => $count('to_pickup'),
            'to_deliver' => $count('to_deliver'),
            'internal_late' => $count('internal_late'),
            'unassigned' => AwaitingCourier::scope($base())->count(),
            'to_reschedule' => $count('to_reschedule'),
            // Cas à régler, toutes dates (tant qu'ils ne sont pas réglés) : cartes et onglets visibles s'il y en a
            'exceptions' => collect(self::EXCEPTION_QUEUES)->mapWithKeys(fn (string $queue) => [$queue => $count($queue)])->all(),
            'late' => $awaiting->lateCounts($request->user()->company),
            // Tableau de bord : en attente d'un livreur, par étape
            'waiting' => $awaiting->summary($request->user()->company),
            // Heure limite du jour : passée ou non, et courses encore sans livreur
            'cutoff' => $awaiting->cutoff($request->user()->company),
            // Liste de l'agence sur un jour : files limitées à ce jour, courses prévues plus tard à part
            ...($day ? ['day' => $this->dayCounts(Carbon::parse($day)->toDateString(), $base)] : []),
        ]]);
    }

    /**
     * Compteurs d'un jour : les courses de ce jour par file, ce qui est prévu plus tard, et le nombre de courses
     * d'aujourd'hui et de demain pour le sélecteur.
     *
     * @param  callable(): Builder  $base
     * @return array<string, mixed>
     */
    private function dayCounts(string $day, callable $base): array
    {
        $queue = fn (string $name) => $name === 'unassigned' ? AwaitingCourier::scope($base(), null, $day) : $this->applyQueue($base(), $name, $day);
        $later = fn (string $name) => $queue($name)->whereRaw(Order::DELIVERY_DAY_SQL.' > ?', [$day])->count();

        return [
            'date' => $day,
            'all' => $base()->ofDay($day)->count(),
            ...collect(self::DAY_QUEUES)->mapWithKeys(fn (string $name) => [$name => $queue($name)->ofDay($day)->count()])->all(),
            ...collect(self::WORK_QUEUES)->mapWithKeys(fn (string $name) => [$name => $queue($name)->ofDay($day)->count()])->all(),
            'later' => collect(self::LATER_QUEUES)->mapWithKeys(fn (string $name) => [$name => $later($name)])->all(),
            'days' => [
                'today' => $base()->ofDay(today()->toDateString())->count(),
                'tomorrow' => $base()->ofDay(today()->addDay()->toDateString())->count(),
            ],
        ];
    }

    /** Onglets limités aux courses du jour choisi (les jours passés sont dans « Courses non traitées ») */
    private const DAY_QUEUES = ['to_confirm', 'to_return', 'failed'];

    /** Cas à régler comptés toutes dates : la page Courses ne montre leur onglet ou leur carte que s'il y en a */
    private const EXCEPTION_QUEUES = ['untreated', 'to_approve', 'to_return', 'failed', 'to_decide', 'incidents', 'scheduled', 'on_hold', 'follow_up', 'code_to_verify', 'internal_late', 'to_prepare'];

    /** Files de travail : limitées au jour choisi (les jours passés sont dans « Courses non traitées ») */
    private const WORK_QUEUES = ['to_pickup', 'to_prepare', 'to_deliver', 'unassigned'];

    /** Files où l'on peut ajouter les courses prévues plus tard (ramassage ou préparation en avance) */
    private const LATER_QUEUES = ['to_pickup', 'to_prepare', 'unassigned'];

    /**
     * Jour choisi dans la liste : « Toutes » montre les courses du jour et les retards encore en cours, les files
     * de travail ce qui est dû (plus, sur demande, ce qui est prévu plus tard) ; les autres files (à valider,
     * à décider, incidents, retours, codes) restent complètes : un problème reste visible jusqu'à sa résolution.
     */
    private function applyDay(Builder $query, string $queue, string $day, bool $includeLater): Builder
    {
        return match (true) {
            $queue === '' => $query->ofDay($day),
            in_array($queue, self::DAY_QUEUES, true) => $query->ofDay($day),
            in_array($queue, self::WORK_QUEUES, true) => $includeLater && in_array($queue, self::LATER_QUEUES, true)
                ? $query->where(fn ($q) => $q->ofDay($day)->orWhereRaw(Order::DELIVERY_DAY_SQL.' > ?', [$day]))
                : $query->ofDay($day),
            default => $query,
        };
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $merchant = Merchant::findOrFail($user->merchant_id ?? $data['merchant_id']);

        $source = $user->merchant_id !== null ? 'dashboard' : 'admin';
        $order = $this->orders->create($user, $merchant, $data, $source);

        return OrderResource::make($this->loadDetail($order))->response()->setStatusCode(201);
    }

    public function show(Order $order): OrderResource
    {
        Gate::authorize('view', $order);

        return OrderResource::make($this->loadDetail($order));
    }

    public function update(UpdateOrderRequest $request, Order $order): OrderResource
    {
        $order = $this->orders->update($request->user(), $order, $request->validated());

        return OrderResource::make($this->loadDetail($order));
    }

    public static function loadDetailRelations(Request $request): array
    {
        $user = $request->user();
        $isMerchant = $user?->merchant_id !== null;
        // Livreur : l'historique partagé avec l'e-commerçant et ses propres notes, pas les notes internes de l'équipe
        $isCourier = ! $isMerchant && $user?->isCourier() === true;

        return [
            ...self::LIST_RELATIONS,
            'events' => fn ($q) => $q->when($isMerchant, fn ($q) => $q->where('visible_to_merchant', true))
                ->when($isCourier, fn ($q) => $q->where(fn ($q) => $q->where('visible_to_merchant', true)
                    ->orWhere(fn ($q) => $q->where('actor_type', 'user')->where('actor_id', $user->id))))
                ->with(['incidentReason', 'attachments'])
                ->orderBy('id'),
            'assignments' => fn ($q) => $q->with(['courier.user', 'assignedBy'])->orderBy('id'),
            // Le client ne voit que les frais qui lui sont facturés
            'expenses' => fn ($q) => $q->when($isMerchant, fn ($q) => $q->where('billed_to', 'merchant'))
                ->with('courier.user')->orderBy('id'),
        ];
    }

    private function loadDetail(Order $order): Order
    {
        return $order->fresh()->load(self::loadDetailRelations(request()));
    }

    private function applyQueue(Builder $query, string $queue, ?string $day = null): Builder
    {
        $day ??= today()->toDateString();

        return match ($queue) {
            // Les demandes d'agents pas encore validées par leur chef de service ne sont pas encore à valider ici
            'to_confirm' => $query->where('status', OrderStatus::Pending)->where(fn ($q) => $q->whereNull('approval_requested_at')->orWhereNotNull('approved_at')),
            'to_approve' => $query->where('status', OrderStatus::Pending)->whereNotNull('approval_requested_at')->whereNull('approved_at'),
            // Mises en attente par une note de suivi de l'équipe
            'on_hold' => $query->whereIn('follow_up_reason_id', IncidentReason::query()->where('holds', true)->select('id')),
            'follow_up' => $query->whereNotNull('follow_up_reason_id'),
            // Ramassages en cours : un livreur est assigné, le colis n'est pas encore récupéré
            // (les ramassages sans livreur sont dans « Sans livreur » et « Ramassages par client »)
            'to_pickup' => $query->whereIn('status', OrderStatus::values([OrderStatus::PickupAssigned, OrderStatus::PickupInProgress]))->whereNull('pickup_hub_id')
                ->withoutToReschedule(today()->toDateString()),
            // À reporter : date de livraison passée, ramassage pas commencé (cachées chez les livreurs)
            'to_reschedule' => $query->toReschedule(today()->toDateString()),
            // Commandes d'entrepôt validées, à préparer avant la livraison
            'to_prepare' => $query->where('status', OrderStatus::Confirmed)->whereNotNull('pickup_hub_id'),
            // À livrer aujourd'hui : colis disponibles et reports arrivés à échéance
            // (colis ramassé pour une livraison un autre jour : pas encore à livrer)
            'to_deliver' => $query->whereIn('status', OrderStatus::values([OrderStatus::PickedUp, OrderStatus::AtHub, OrderStatus::Rescheduled]))
                ->where(fn ($q) => $q->whereNull('delivery_scheduled_date')
                    ->orWhereDate('delivery_scheduled_date', '<=', $day))
                ->where('return_requested', false)
                ->whereDoesntHave('assignments', fn ($q) => $q->active()->where('type', AssignmentType::Delivery->value)),
            // Échecs sans suite : relivrer, retourner ou remettre en stock
            'to_decide' => $query->needingDecision(),
            // Livraisons prévues un autre jour : reports, colis ramassés en avance et courses pas encore ramassées
            'scheduled' => $query->whereIn('status', OrderStatus::values([OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::PickupAssigned, OrderStatus::PickupInProgress, OrderStatus::Rescheduled, OrderStatus::PickedUp, OrderStatus::AtHub]))
                ->whereDate('delivery_scheduled_date', '>', $day)
                ->where('return_requested', false),
            'to_return' => $query->where('return_requested', true)
                ->whereNotIn('status', OrderStatus::values([OrderStatus::ReturnAssigned, OrderStatus::Returning, OrderStatus::Returned])),
            'incidents' => $query->whereIn('status', OrderStatus::values([OrderStatus::DeliveryFailed, OrderStatus::Rescheduled])),
            // Échec de livraison
            'failed' => $query->where('status', OrderStatus::DeliveryFailed),
            // Non traitées : jour dépassé, pas terminée, plus dans la règle de son activité (toutes dates)
            'untreated' => $query->untreated(),
            // Livrées hors connexion avec un code client incorrect : à vérifier auprès du destinataire
            'code_to_verify' => $query->whereNotNull('delivery_code_unverified_at'),
            // Délai interne de distribution dépassé, course pas terminée
            'internal_late' => $query->whereNotNull('internal_due_at')->where('internal_due_at', '<=', now())
                ->whereNotIn('status', OrderStatus::values(array_filter(OrderStatus::cases(), fn (OrderStatus $s) => $s->isFinal()))),
        };
    }
}
