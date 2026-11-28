<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\ReturnHandover;
use App\Services\Orders\ReturnHandovers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Remise au client des colis retournés, confirmée par le client : écran du livreur (QR code, remise de
 * secours), bandeau de l'espace client, remises à vérifier par l'agence.
 */
class ReturnHandoverController extends Controller
{
    public function __construct(private readonly ReturnHandovers $handovers) {}

    // --- Livreur ---

    /** Le livreur est chez le client : remise en attente, QR code */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['merchant_id' => ['required', 'integer']]);
        $merchant = Merchant::query()->findOrFail($data['merchant_id']);

        return response()->json(['data' => $this->data($this->handovers->start($request->user(), $merchant), true)]);
    }

    public function show(Request $request, ReturnHandover $returnHandover): JsonResponse
    {
        $this->authorizeCourier($request, $returnHandover);

        return response()->json(['data' => $this->data($returnHandover, true)]);
    }

    public function renew(Request $request, ReturnHandover $returnHandover): JsonResponse
    {
        return response()->json(['data' => $this->data($this->handovers->renew($request->user(), $returnHandover), true)]);
    }

    public function cancel(Request $request, ReturnHandover $returnHandover): JsonResponse
    {
        return response()->json(['data' => $this->data($this->handovers->cancel($request->user(), $returnHandover), true)]);
    }

    public function fallback(Request $request, ReturnHandover $returnHandover): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(ReturnHandover::FALLBACK_REASONS))],
            'received_by_name' => ['required', 'string', 'max:120'],
            'signature' => ['nullable', 'string', 'max:800000'],
            'photo' => ['nullable', 'image', 'max:8192'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $handover = $this->handovers->fallback(
            $request->user(), $returnHandover, $data['reason'], $data['received_by_name'],
            $data['signature'] ?? null, $request->file('photo'),
            isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null,
        );

        return response()->json(['data' => $this->data($handover, true)]);
    }

    // --- Client ---

    /** Remises que le client peut confirmer (bandeau de son accueil) */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->merchant_id === null, 403);

        return response()->json(['data' => $this->handovers->awaitingMerchant($user->company_id, $user->merchant_id)
            ->map(fn (ReturnHandover $h) => $this->data($h))->values()]);
    }

    public function confirm(Request $request, ReturnHandover $returnHandover): JsonResponse
    {
        $this->authorizeMerchant($request, $returnHandover);

        return response()->json(['data' => $this->data($this->handovers->confirm($returnHandover, 'app'))]);
    }

    public function dispute(Request $request, ReturnHandover $returnHandover): JsonResponse
    {
        $this->authorizeMerchant($request, $returnHandover);

        return response()->json(['data' => $this->data($this->handovers->dispute($returnHandover))]);
    }

    // --- Agence ---

    /** Remises à vérifier : de secours ou contestées */
    public function review(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permission::OrdersDispatch->value), 403);

        $list = ReturnHandover::query()->with(['merchant', 'courier.user'])
            ->whereIn('status', [ReturnHandover::UNCONFIRMED, ReturnHandover::DISPUTED])
            ->latest('id')->limit(100)->get();

        return response()->json(['data' => $list->map(fn (ReturnHandover $h) => $this->data($h, true))->values()]);
    }

    public function resolve(Request $request, ReturnHandover $returnHandover): JsonResponse
    {
        abort_unless($request->user()->can(Permission::OrdersDispatch->value), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['returned', 'kept', 'verified'])]]);

        return response()->json(['data' => $this->data($this->handovers->resolve($request->user(), $returnHandover, $data['decision']), true)]);
    }

    public function proof(Request $request, ReturnHandover $returnHandover, string $kind): StreamedResponse
    {
        abort_unless($request->user()->can(Permission::OrdersDispatch->value) || $request->user()->courier?->id === $returnHandover->courier_id, 403);
        $path = $kind === 'signature' ? $returnHandover->signature_path : $returnHandover->photo_path;
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    private function authorizeCourier(Request $request, ReturnHandover $handover): void
    {
        abort_unless($request->user()->courier?->id === $handover->courier_id, 403);
    }

    private function authorizeMerchant(Request $request, ReturnHandover $handover): void
    {
        $user = $request->user();
        abort_unless($user->merchant_id !== null && $user->merchant_id === $handover->merchant_id, 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ReturnHandover $h, bool $full = false): array
    {
        $h->loadMissing(['merchant', 'courier.user']);
        $orders = $h->orders();

        return [
            'id' => $h->id,
            'reference' => $h->reference,
            'status' => $h->isExpired() ? 'expired' : $h->status,
            'expires_at' => $h->expires_at?->toIso8601String(),
            'confirmed_at' => $h->confirmed_at?->toIso8601String(),
            'confirmed_via' => $h->confirmed_via,
            'handed_at' => $h->handed_at?->toIso8601String(),
            'disputed_at' => $h->disputed_at?->toIso8601String(),
            'resolution' => $h->resolution,
            'courier' => ['id' => $h->courier_id, 'name' => $h->courier?->user?->name],
            'merchant' => ['id' => $h->merchant_id, 'business_name' => $h->merchant?->business_name],
            'orders' => $orders->map(fn (Order $o) => [
                'id' => $o->id, 'tracking_code' => $o->tracking_code, 'recipient_name' => $o->recipient_name,
                'status' => $o->status->value, 'status_label' => $o->status->label(),
            ])->values(),
            ...($full ? [
                'whatsapp_link' => $h->status === ReturnHandover::PENDING ? $this->handovers->whatsappLink($h) : null,
                'message' => $h->message(),
                'fallback_reason' => $h->fallback_reason,
                'fallback_label' => ReturnHandover::FALLBACK_REASONS[$h->fallback_reason] ?? null,
                'received_by_name' => $h->received_by_name,
                'has_signature' => $h->signature_path !== null,
                'has_photo' => $h->photo_path !== null,
                'lat' => $h->lat,
                'lng' => $h->lng,
            ] : []),
        ];
    }
}
