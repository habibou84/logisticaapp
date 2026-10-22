<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\ReturnSlip;
use App\Models\User;
use App\Services\Orders\ReturnSlips;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bons de retour groupés par marchand.
 */
class ReturnSlipController extends Controller
{
    public function __construct(private readonly ReturnSlips $slips) {}

    /**
     * Colis à rendre par marchand, bons en cours et derniers bons remis.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeDispatch($request);

        $candidates = $this->slips->candidates()->groupBy('merchant_id')->map(fn ($orders) => [
            'merchant' => $this->merchantData($orders->first()->merchant),
            'orders' => $orders->map(fn (Order $o) => $this->orderData($o))->values(),
        ])->values();

        $slips = ReturnSlip::with(['merchant', 'courier.user'])->withCount('orders')
            ->where(fn ($q) => $q->where('status', ReturnSlip::OPEN)->orWhere('updated_at', '>=', now()->subDays(30)))
            ->latest('id')->limit(100)->get();

        return response()->json([
            'candidates' => $candidates,
            'data' => $slips->map(fn (ReturnSlip $s) => $this->slipData($s)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeDispatch($request);
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'merchant_id' => ['required', 'integer', Rule::exists('merchants', 'id')->where('company_id', $companyId)],
            'courier_id' => ['required', 'integer', Rule::exists('couriers', 'id')->where('company_id', $companyId)],
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['merchant_id' => 'marchand', 'courier_id' => 'livreur', 'order_ids' => 'colis']);

        $slip = $this->slips->create(
            $request->user(),
            Merchant::findOrFail($data['merchant_id']),
            Courier::findOrFail($data['courier_id']),
            $data['order_ids'],
            $data['notes'] ?? null,
        );

        return response()->json(['data' => $this->detail($slip)], 201);
    }

    public function show(Request $request, ReturnSlip $returnSlip): JsonResponse
    {
        $this->authorizeView($request->user(), $returnSlip);

        return response()->json(['data' => $this->detail($returnSlip)]);
    }

    /**
     * Bons en cours du livreur connecté.
     */
    public function mine(Request $request): JsonResponse
    {
        $courier = $request->user()->courier;
        abort_if($courier === null, 403);

        $slips = ReturnSlip::with(['merchant', 'courier.user'])->withCount('orders')
            ->where('courier_id', $courier->id)->where('status', ReturnSlip::OPEN)
            ->latest('id')->get();

        return response()->json(['data' => $slips->map(fn (ReturnSlip $s) => $this->slipData($s))]);
    }

    public function handOver(Request $request, ReturnSlip $returnSlip): JsonResponse
    {
        abort_unless($request->user()->isCourier(), 403);

        $data = $request->validate([
            'received_by_name' => ['required', 'string', 'max:120'],
            'signature' => ['nullable', 'string', 'max:800000'],
            'photo' => ['nullable', 'image', 'max:5120'],
            'order_ids' => ['nullable', 'array'],
            'order_ids.*' => ['integer'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ], [], ['received_by_name' => 'nom de la personne qui reçoit']);

        $slip = $this->slips->handOver(
            $request->user(),
            $returnSlip,
            trim($data['received_by_name']),
            $data['signature'] ?? null,
            $request->file('photo'),
            $data['order_ids'] ?? null,
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
        );

        return response()->json(['data' => $this->detail($slip)]);
    }

    public function cancel(Request $request, ReturnSlip $returnSlip): JsonResponse
    {
        $this->authorizeDispatch($request);

        return response()->json(['data' => $this->detail($this->slips->cancel($request->user(), $returnSlip))]);
    }

    /**
     * Signature ou photo de la remise.
     */
    public function proof(Request $request, ReturnSlip $returnSlip, string $kind): StreamedResponse
    {
        $this->authorizeView($request->user(), $returnSlip);

        $path = match ($kind) {
            'signature' => $returnSlip->signature_path,
            'photo' => $returnSlip->photo_path,
            default => null,
        };
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    private function authorizeDispatch(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->merchant_id === null && $user->can(Permission::OrdersDispatch->value), 403);
    }

    private function authorizeView(User $user, ReturnSlip $slip): void
    {
        abort_unless(match (true) {
            $user->merchant_id !== null => $user->merchant_id === $slip->merchant_id,
            $user->isCourier() => $user->courier?->id === $slip->courier_id,
            default => $user->can(Permission::OrdersView->value),
        }, 403);
    }

    private function detail(ReturnSlip $slip): array
    {
        $slip->load(['merchant.pickupZone', 'courier.user', 'creator', 'orders' => fn ($q) => $q->with(['deliveryZone:id,name', 'lastIncidentReason:id,label'])->orderBy('id')]);

        return [
            ...$this->slipData($slip),
            'company' => $slip->company?->name,
            'created_by' => $slip->creator?->name,
            'notes' => $slip->notes,
            'orders' => $slip->orders->map(fn (Order $o) => $this->orderData($o))->values(),
            'signature_url' => $slip->signature_path ? "/api/v1/return-slips/{$slip->id}/proof/signature" : null,
            'photo_url' => $slip->photo_path ? "/api/v1/return-slips/{$slip->id}/proof/photo" : null,
        ];
    }

    private function slipData(ReturnSlip $slip): array
    {
        return [
            'id' => $slip->id,
            'reference' => $slip->reference,
            'status' => $slip->status,
            'status_label' => $slip->statusLabel(),
            'merchant' => $this->merchantData($slip->merchant),
            'courier' => $slip->courier ? ['id' => $slip->courier->id, 'name' => $slip->courier->user?->name, 'phone' => $slip->courier->user?->phone] : null,
            'orders_count' => $slip->orders_count ?? $slip->orders()->count(),
            'created_at' => $slip->created_at,
            'handed_at' => $slip->handed_at,
            'received_by_name' => $slip->received_by_name,
        ];
    }

    private function merchantData(?Merchant $merchant): ?array
    {
        return $merchant ? [
            'id' => $merchant->id,
            'business_name' => $merchant->business_name,
            'phone' => $merchant->phone,
            'zone' => $merchant->pickupZone?->name,
            'address' => $merchant->pickup_address,
        ] : null;
    }

    private function orderData(Order $o): array
    {
        return [
            'id' => $o->id,
            'tracking_code' => $o->tracking_code,
            'status' => $o->status->value,
            'status_label' => $o->status->label(),
            'recipient_name' => $o->recipient_name,
            'recipient_phone' => $o->recipient_phone,
            'zone' => $o->deliveryZone?->name,
            'items_amount' => $o->items_amount,
            'incident' => $o->lastIncidentReason?->label,
            'attempts_count' => $o->attempts_count,
            'held_by' => $o->relationLoaded('holder') && $o->holder ? ['id' => $o->holder->id, 'name' => $o->holder->user?->name] : null,
            'return_courier' => $o->relationLoaded('returnCourier') && $o->returnCourier ? ['id' => $o->returnCourier->id, 'name' => $o->returnCourier->user?->name] : null,
        ];
    }
}
