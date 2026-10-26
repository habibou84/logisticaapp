<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OrderResource;
use App\Models\Order;
use App\Services\Labels\LabelLinks;
use App\Services\Labels\LabelSettings;
use App\Support\Branding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Étiquettes : impression notée sur les courses, étiquette partagée par un lien signé (API publique).
 */
class LabelController extends Controller
{
    public function __construct(private readonly LabelSettings $settings, private readonly LabelLinks $links) {}

    /**
     * Étiquettes imprimées : date et nombre d'impressions de chaque course. Un client que l'entreprise ne laisse
     * pas imprimer (réglage) ne note rien.
     */
    public function printed(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['integer']]);
        $user = $request->user();
        abort_if($user->isCourier(), 403);
        $orders = Order::query()->whereIn('id', $data['ids'])->with(['merchant', 'company'])->get()
            ->filter(fn (Order $o) => Gate::allows('view', $o));
        if ($user->merchant_id !== null) {
            abort_unless($user->can(Permission::OrdersCreate->value), 403);
            $orders = $orders->filter(fn (Order $o) => $this->settings->for($o->company, $o->merchant)['client_print'] !== 'no');
        }

        return response()->json(['data' => ['count' => $this->mark($orders->modelKeys())]]);
    }

    /** Étiquette partagée (Jibiat Marchand) : sans connexion, avec le lien signé */
    public function shared(int $order, int $expires, string $token): JsonResponse
    {
        $model = $this->sharedOrder($order, $expires, $token);

        return response()->json(['data' => [
            ...OrderResource::make($model)->resolve(request()),
            'label_settings' => $this->settings->for($model->company, $model->merchant),
            'company' => ['name' => $model->company->name, 'logo_url' => Branding::logoUrl($model->company)],
        ]]);
    }

    public function sharedPrinted(int $order, int $expires, string $token): JsonResponse
    {
        $this->mark([$this->sharedOrder($order, $expires, $token)->id]);

        return response()->json(['data' => ['count' => 1]]);
    }

    private function sharedOrder(int $order, int $expires, string $token): Order
    {
        abort_unless($this->links->valid($order, $expires, $token), 403, 'Lien d\'étiquette expiré ou invalide.');

        return Order::withoutGlobalScopes()->with([...OrderController::LIST_RELATIONS, 'company'])->findOrFail($order);
    }

    /** @param list<int> $ids */
    private function mark(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return Order::withoutGlobalScopes()->whereIn('id', $ids)->update([
            'label_printed_at' => now(),
            'label_print_count' => DB::raw('label_print_count + 1'),
        ]);
    }
}
