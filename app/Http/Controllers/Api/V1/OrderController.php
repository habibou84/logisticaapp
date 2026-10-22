<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreOrderRequest;
use App\Http\Requests\V1\UpdateOrderRequest;
use App\Http\Resources\V1\OrderResource;
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
        'merchant', 'pickupZone', 'deliveryZone', 'lastIncidentReason', 'pickupHub', 'items',
        'pickupCourier.user', 'deliveryCourier.user', 'returnCourier.user', 'holder.user', 'merchantDecision',
    ];

    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Order::class);

        $request->validate([
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(OrderStatus::class)],
            // File d'attente du dispatch : à valider, à ramasser, à livrer, à retourner
            'queue' => ['nullable', Rule::in(['to_confirm', 'to_pickup', 'to_prepare', 'to_deliver', 'unassigned', 'to_decide', 'scheduled', 'to_return', 'incidents', 'code_to_verify'])],
            // File « Sans livreur » : ramassage, livraison ou les deux
            'awaiting' => ['nullable', Rule::in([AwaitingCourier::PICKUP, AwaitingCourier::DELIVERY])],
            'merchant_id' => ['nullable', 'integer'],
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
            // Jour choisi : les retards d'abord
            ->when($day !== null && ($queue === '' || in_array($queue, self::WORK_QUEUES, true)), fn ($q) => $q->orderByRaw(Order::DELIVERY_DAY_SQL))
            // Sans livreur : de la plus ancienne attente à la plus récente
            ->when($queue === 'unassigned', fn ($q) => $q->orderBy('status_changed_at'))
            ->when($request->filled('merchant_id'), fn ($q) => $q->where('merchant_id', $request->integer('merchant_id')))
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
            ->paginate($request->integer('per_page', 30));

        return OrderResource::collection($orders);
    }

    /**
     * Compteurs du dispatch : courses à valider, à ramasser, à livrer, et celles qui
     * attendent un livreur au-delà du délai de l'entreprise.
     */
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

        $count = fn (string $queue) => $this->applyQueue(Order::query(), $queue)->count();
        $day = $request->validate(['day' => ['nullable', 'date']])['day'] ?? null;

        return response()->json(['data' => [
            'to_confirm' => $count('to_confirm'),
            'to_pickup' => $count('to_pickup'),
            'to_deliver' => $count('to_deliver'),
            'unassigned' => AwaitingCourier::scope(Order::query())->count(),
            'late' => $awaiting->lateCounts($request->user()->company),
            // Tableau de bord : en attente d'un livreur, par étape
            'waiting' => $awaiting->summary($request->user()->company),
            // Heure limite du jour : passée ou non, et courses encore sans livreur
            'cutoff' => $awaiting->cutoff($request->user()->company),
            // Liste de l'agence sur un jour : files limitées à ce qui est dû, courses prévues plus tard à part
            ...($day ? ['day' => $this->dayCounts(Carbon::parse($day)->toDateString())] : []),
        ]]);
    }

    /**
     * Compteurs d'un jour : ce qui est dû ce jour-là (retards compris) par file, ce qui est prévu plus tard,
     * et le nombre de courses d'aujourd'hui et de demain pour le sélecteur.
     *
     * @return array<string, mixed>
     */
    private function dayCounts(string $day): array
    {
        $queue = fn (string $name) => $name === 'unassigned' ? AwaitingCourier::scope(Order::query(), null, $day) : $this->applyQueue(Order::query(), $name, $day);
        $later = fn (string $name) => $queue($name)->whereRaw(Order::DELIVERY_DAY_SQL.' > ?', [$day])->count();

        return [
            'date' => $day,
            'all' => Order::query()->ofDay($day)->count(),
            'to_confirm' => $queue('to_confirm')->count(),
            ...collect(self::WORK_QUEUES)->mapWithKeys(fn (string $name) => [$name => $queue($name)->dueBy($day)->count()])->all(),
            'later' => collect(self::LATER_QUEUES)->mapWithKeys(fn (string $name) => [$name => $later($name)])->all(),
            'days' => [
                'today' => Order::query()->ofDay(today()->toDateString())->count(),
                'tomorrow' => Order::query()->ofDay(today()->addDay()->toDateString())->count(),
            ],
        ];
    }

    /** Files de travail : limitées au jour choisi et aux retards */
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
            in_array($queue, self::WORK_QUEUES, true) => $includeLater && in_array($queue, self::LATER_QUEUES, true) ? $query : $query->dueBy($day),
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
        $isMerchant = $request->user()?->merchant_id !== null;

        return [
            ...self::LIST_RELATIONS,
            'events' => fn ($q) => $q->when($isMerchant, fn ($q) => $q->where('visible_to_merchant', true))
                ->with(['incidentReason', 'attachments'])
                ->orderBy('id'),
            'assignments' => fn ($q) => $q->with(['courier.user', 'assignedBy'])->orderBy('id'),
            // Le marchand ne voit que les frais qui lui sont facturés
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
            'to_confirm' => $query->where('status', OrderStatus::Pending),
            'to_pickup' => $query->where('status', OrderStatus::Confirmed)->whereNull('pickup_hub_id'),
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
            // Livrées hors connexion avec un code client incorrect : à vérifier auprès du destinataire
            'code_to_verify' => $query->whereNotNull('delivery_code_unverified_at'),
        };
    }
}
