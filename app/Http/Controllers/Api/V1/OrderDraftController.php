<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Zone;
use App\Services\WhatsApp\OrderMessageParser;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Aide à la saisie d'une course : analyse d'un message collé (même moteur que le robot WhatsApp)
 * et alerte de doublon.
 */
class OrderDraftController extends Controller
{
    /**
     * Champs de la course trouvés dans le message ; la commune la plus probable en premier.
     */
    public function parse(Request $request, OrderMessageParser $parser): JsonResponse
    {
        Gate::authorize('create', Order::class);

        $data = $request->validate([
            'text' => ['required', 'string', 'max:4000'],
            'merchant_id' => ['nullable', 'integer'],
        ], [], ['text' => 'message']);

        $user = $request->user();
        $zones = Zone::forCompany($user->company_id)->active()->with('parent')->get();
        $fields = $parser->parse($data['text'], $zones);

        // « De Cocody à Yopougon » : la commune de ramassage du marchand n'est pas la destination
        $merchant = Merchant::find($user->merchant_id ?? $data['merchant_id'] ?? null);
        $candidates = $fields['zones'] ?? [];
        if (count($candidates) > 1 && $merchant?->pickup_zone_id) {
            $candidates = array_values(array_diff($candidates, [$merchant->pickup_zone_id])) ?: $candidates;
        }
        unset($fields['zones']);

        return response()->json(['data' => [
            ...$fields,
            'zones' => collect($candidates)->take(3)
                ->map(fn (int $id) => $zones->firstWhere('id', $id))
                ->filter()
                ->map(fn (Zone $zone) => ['id' => $zone->id, 'full_name' => $zone->fullName()])
                ->values(),
        ]]);
    }

    /**
     * Courses du même client chez ce marchand ces dernières 24 heures (hors annulées et refusées).
     */
    public function duplicates(Request $request): JsonResponse
    {
        Gate::authorize('create', Order::class);

        $data = $request->validate([
            'recipient_phone' => ['required', 'string', 'max:30'],
            'merchant_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $phone = PhoneNumber::normalize($data['recipient_phone']);
        $merchantId = $user->merchant_id ?? $data['merchant_id'] ?? null;
        if ($phone === null || $merchantId === null) {
            return response()->json(['data' => []]);
        }

        $orders = Order::query()
            ->visibleTo($user)
            ->where('merchant_id', $merchantId)
            ->where(fn ($q) => $q->where('recipient_phone', $phone)->orWhere('recipient_phone2', $phone))
            ->where('created_at', '>=', now()->subDay())
            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Rejected->value])
            ->with('deliveryZone:id,name')
            ->latest()
            ->limit(5)
            ->get();

        return response()->json(['data' => $orders->map(fn (Order $o) => [
            'id' => $o->id,
            'tracking_code' => $o->tracking_code,
            'status_label' => $o->status->label(),
            'delivery_zone_name' => $o->deliveryZone?->name,
            'cod_amount' => (int) $o->cod_amount,
            'created_at' => $o->created_at,
        ])]);
    }
}
