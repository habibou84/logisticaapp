<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Order;
use App\Services\Couriers\ParcelCustody;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Colis chez les livreurs : vue d'ensemble pour le dispatch et réception au dépôt.
 */
class ParcelController extends Controller
{
    public function __construct(private readonly ParcelCustody $custody) {}

    /** Colis d'un livreur chargés à la fois */
    public const PER_PAGE = 25;

    /**
     * Résumé par livreur des colis en main (le plus ancien en tête), compté par la base : les colis eux-mêmes se
     * chargent livreur par livreur (parcels()). Filtres : en retard, recherche (code, client, e-commerçant, livreur).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        $request->validate([
            'courier_id' => ['nullable', 'integer'],
            'overdue' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $hours = $this->alertHours($request);
        $limit = ($hours > 0 ? now()->subHours($hours) : now()->subYears(100))->toDateTimeString();

        $rows = $this->filtered($request, $hours)
            ->when($request->filled('courier_id'), fn ($q) => $q->where('held_by_courier_id', $request->integer('courier_id')))
            ->groupBy('held_by_courier_id')
            ->selectRaw('held_by_courier_id, COUNT(*) AS n, SUM(CASE WHEN held_since <= ? THEN 1 ELSE 0 END) AS overdue, MIN(held_since) AS oldest', [$limit])
            ->get();

        $couriers = Courier::query()->whereIn('id', $rows->pluck('held_by_courier_id'))->with('user:id,name,phone')->get()->keyBy('id');

        $data = $rows->map(fn ($row) => [
            'courier_id' => (int) $row->held_by_courier_id,
            'name' => $couriers[$row->held_by_courier_id]?->user?->name,
            'phone' => $couriers[$row->held_by_courier_id]?->user?->phone,
            'is_available' => (bool) $couriers[$row->held_by_courier_id]?->is_available,
            'count' => (int) $row->n,
            'overdue' => $hours > 0 ? (int) $row->overdue : 0,
            'oldest' => $row->oldest ? Carbon::parse($row->oldest)->toIso8601String() : null,
        ])->sortBy('oldest')->values();

        return response()->json([
            'data' => $data,
            'meta' => ['alert_hours' => $hours, 'per_page' => self::PER_PAGE, ...$this->countsData($hours)],
        ]);
    }

    /**
     * Colis en main d'un livreur, du plus ancien au plus récent, 25 par 25 (mêmes filtres que le résumé).
     */
    public function parcels(Request $request, Courier $courier): JsonResponse
    {
        $this->authorizeView($request);

        $request->validate([
            'overdue' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $this->filtered($request, $this->alertHours($request))
            ->where('held_by_courier_id', $courier->id)
            ->with(['merchant:id,business_name', 'deliveryZone:id,name', 'lastIncidentReason:id,label'])
            ->orderBy('held_since')->orderBy('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (Order $o) => ParcelCustody::parcelData($o))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Colis en main, filtrés : en retard seulement, recherche par code, client, téléphone, e-commerçant ou livreur.
     *
     * @return Builder<Order>
     */
    private function filtered(Request $request, int $hours): Builder
    {
        $term = trim((string) $request->input('q'));
        $like = '%'.mb_strtolower($term).'%';

        return ParcelCustody::heldQuery()
            ->when($request->boolean('overdue') && $hours > 0, fn ($q) => $q->where('held_since', '<=', now()->subHours($hours)))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereRaw('LOWER(tracking_code) LIKE ?', [$like])
                ->orWhereRaw('LOWER(recipient_name) LIKE ?', [$like])
                ->orWhere('recipient_phone', 'like', '%'.$term.'%')
                ->orWhereHas('merchant', fn ($m) => $m->whereRaw('LOWER(business_name) LIKE ?', [$like]))
                ->orWhereHas('holder.user', fn ($u) => $u->whereRaw('LOWER(name) LIKE ?', [$like]))));
    }

    /**
     * Compteurs pour le badge du menu.
     */
    public function counts(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        return response()->json(['data' => $this->countsData($this->alertHours($request))]);
    }

    /**
     * Colis rendus au dépôt en dehors d'un versement (livreur sans argent à verser, agent de dépôt).
     */
    public function receive(Request $request, Courier $courier): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->merchant_id === null && ! $user->isCourier() && (
            $user->can(Permission::FinanceManage->value)
            || $user->can(Permission::OrdersDispatch->value)
            || $user->can(Permission::OrdersUpdateStatus->value)
        ), 403);

        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', Rule::exists('orders', 'id')->where('company_id', $user->company_id)],
        ], [], ['order_ids' => 'colis']);

        $received = $this->custody->receive($user, $courier, $data['order_ids']);

        return response()->json([
            'data' => ['received' => $received->pluck('tracking_code')->values()],
            'parcels' => $this->custody->held($courier)->map(fn ($o) => ParcelCustody::parcelData($o))->values(),
        ]);
    }

    private function authorizeView(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->merchant_id === null && ($user->can(Permission::OrdersDispatch->value) || $user->can(Permission::FinanceView->value)), 403);
    }

    private function alertHours(Request $request): int
    {
        return (int) ($request->user()->company?->parcel_hold_alert_hours ?? 24);
    }

    /**
     * @return array{total: int, overdue: int}
     */
    private function countsData(int $hours): array
    {
        return [
            'total' => ParcelCustody::heldQuery()->count(),
            'overdue' => $hours > 0 ? ParcelCustody::heldQuery()->where('held_since', '<=', now()->subHours($hours))->count() : 0,
        ];
    }
}
