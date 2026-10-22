<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Order;
use App\Services\Couriers\ParcelCustody;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Colis chez les livreurs : vue d'ensemble pour le dispatch et réception au dépôt.
 */
class ParcelController extends Controller
{
    public function __construct(private readonly ParcelCustody $custody) {}

    /**
     * Colis en main des livreurs, regroupés par livreur (le plus ancien en tête).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        $request->validate([
            'courier_id' => ['nullable', 'integer'],
            'overdue' => ['nullable', 'boolean'],
        ]);

        $hours = $this->alertHours($request);

        $orders = ParcelCustody::heldQuery()
            ->with(['holder.user', 'merchant:id,business_name', 'deliveryZone:id,name', 'lastIncidentReason:id,label'])
            ->when($request->filled('courier_id'), fn ($q) => $q->where('held_by_courier_id', $request->integer('courier_id')))
            ->when($request->boolean('overdue') && $hours > 0, fn ($q) => $q->where('held_since', '<=', now()->subHours($hours)))
            ->orderBy('held_since')
            ->get();

        $couriers = $orders->groupBy('held_by_courier_id')->map(function ($parcels) use ($hours) {
            $courier = $parcels->first()->holder;

            return [
                'courier_id' => $courier->id,
                'name' => $courier->user?->name,
                'phone' => $courier->user?->phone,
                'is_available' => $courier->is_available,
                'count' => $parcels->count(),
                'overdue' => $hours > 0 ? $parcels->filter(fn (Order $o) => $o->held_since?->lte(now()->subHours($hours)))->count() : 0,
                'oldest' => $parcels->first()->held_since,
                'parcels' => $parcels->map(fn (Order $o) => ParcelCustody::parcelData($o))->values(),
            ];
        })->sortBy('oldest')->values();

        return response()->json([
            'data' => $couriers,
            'meta' => ['alert_hours' => $hours, ...$this->countsData($hours)],
        ]);
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
