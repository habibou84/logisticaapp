<?php

namespace App\Http\Controllers\Api\Connect;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\ConnectRequest;
use App\Services\Connect\AppConnect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Connexion en un clic (gérant d'une boutique connecté) et demandes d'ouverture de compte (équipe).
 */
class ConnectController extends Controller
{
    public function __construct(private readonly AppConnect $connect) {}

    /**
     * Le gérant autorise l'application : adresse de retour, avec le code, vers laquelle le renvoyer.
     */
    public function authorize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:60'],
            'redirect_uri' => ['required', 'string', 'max:2000'],
            'state' => ['required', 'string', 'max:255'],
            'code_challenge' => ['required', 'string', 'max:128'],
            'code_challenge_method' => ['nullable', 'string', 'in:S256'],
        ]);

        $redirect = $this->connect->authorize($request->user(), $data['client_id'], $data['redirect_uri'], $data['state'], $data['code_challenge'], $data['code_challenge_method'] ?? 'S256');

        return response()->json(['data' => ['redirect' => $redirect]]);
    }

    public function requests(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permission::MerchantsManage->value), 403);

        $requests = ConnectRequest::query()->with('pickupZone')
            ->when(! $request->boolean('all'), fn ($q) => $q->where('status', ConnectRequest::PENDING))
            ->latest()->limit(100)->get()
            ->map(fn (ConnectRequest $r) => [
                'id' => $r->id,
                'business_name' => $r->business_name,
                'contact_name' => $r->contact_name,
                'phone' => $r->phone,
                'email' => $r->email,
                'pickup_zone' => $r->pickupZone?->name,
                'pickup_address' => $r->pickup_address,
                'message' => $r->message,
                'from_app' => $r->client_id ? config("connect.clients.{$r->client_id}.name") : null,
                'status' => $r->status,
                'merchant_id' => $r->merchant_id,
                'created_at' => $r->created_at,
            ]);

        return response()->json(['data' => $requests, 'pending' => ConnectRequest::query()->where('status', ConnectRequest::PENDING)->count()]);
    }

    public function accept(Request $request, ConnectRequest $connectRequest): JsonResponse
    {
        abort_unless($request->user()->can(Permission::MerchantsManage->value), 403);
        $merchant = $this->connect->accept($connectRequest, $request->user());

        return response()->json(['data' => ['merchant_id' => $merchant->id]]);
    }

    public function decline(Request $request, ConnectRequest $connectRequest): JsonResponse
    {
        abort_unless($request->user()->can(Permission::MerchantsManage->value), 403);
        $this->connect->decline($connectRequest, $request->user());

        return response()->json(null, 204);
    }
}
