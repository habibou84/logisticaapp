<?php

namespace App\Http\Controllers\Api\Connect;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Zone;
use App\Rules\PhoneNumber;
use App\Services\Connect\AppConnect;
use App\Services\Connect\ConnectException;
use App\Services\Connect\Directory;
use App\Support\Branding;
use App\Support\PhoneNumber as Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Annuaire public et connexion en un clic : pages sans compte, sur l'adresse de la plateforme
 * (annuaire) ou de l'entreprise de livraison (connexion).
 */
class PublicConnectController extends Controller
{
    public function __construct(private readonly AppConnect $connect) {}

    public function directory(Directory $directory): JsonResponse
    {
        return response()->json(['data' => $directory->all()])->header('Cache-Control', 'public, max-age=600');
    }

    /**
     * Écran « Connecter » : application et adresse de retour vérifiées, marque de l'entreprise,
     * ouverture de compte seule ou sur demande.
     */
    public function client(Request $request): JsonResponse
    {
        $company = $this->company();
        $client = $this->connect->client($request->query('client_id'), $request->query('redirect_uri'));

        return response()->json(['data' => [
            'client' => ['id' => $client['id'], 'name' => $client['name']],
            'redirect_uri' => $client['redirect_uri'],
            'scopes' => $client['scopes'],
            'company' => ['name' => $company->name, 'slug' => $company->slug],
            'accepts' => $company->merchant_signup ? 'signup' : 'request',
            'zones' => $company->merchant_signup ? [] : Zone::forCompany($company->id)->active()->where('is_shipping', false)->with('parent')->get()
                ->map(fn (Zone $z) => ['id' => $z->id, 'name' => $z->parent ? "{$z->name} ({$z->parent->name})" : $z->name])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values(),
        ]]);
    }

    /**
     * Demande d'ouverture de compte (inscriptions fermées).
     */
    public function requestAccount(Request $request): JsonResponse
    {
        $company = $this->company();
        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => Phone::normalize($request->input('phone')) ?? $request->input('phone')]);
        }

        $data = $request->validate([
            'client_id' => ['nullable', 'string', 'max:60'],
            'business_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', new PhoneNumber],
            'email' => ['nullable', 'email', 'max:255'],
            'pickup_zone_id' => ['nullable', 'integer', Rule::exists('zones', 'id')->where(fn ($q) => $q->where('company_id', $company->id)->where('is_active', true))],
            'pickup_address' => ['nullable', 'string', 'max:500'],
            'message' => ['nullable', 'string', 'max:500'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ], [], [
            'business_name' => 'nom de la boutique',
            'contact_name' => 'nom du responsable',
            'phone' => 'téléphone',
            'pickup_zone_id' => 'commune de ramassage',
            'pickup_address' => 'adresse de ramassage',
            'password' => 'mot de passe',
        ]);

        $connectRequest = $this->connect->request($company, [...$data, 'password_hash' => Hash::make($data['password'])], $data['client_id'] ?? null);

        return response()->json(['data' => ['id' => $connectRequest->id, 'status' => $connectRequest->status]], 201);
    }

    /**
     * Échange du code contre une clé d'API (appel de serveur à serveur de l'application).
     */
    public function token(Request $request): JsonResponse
    {
        $company = $this->company();
        $data = $request->validate([
            'grant_type' => ['required', 'string'],
            'client_id' => ['required', 'string', 'max:60'],
            'code' => ['required', 'string', 'max:100'],
            'code_verifier' => ['required', 'string', 'min:43', 'max:128'],
            'redirect_uri' => ['required', 'string', 'max:2000'],
        ]);

        if ($data['grant_type'] !== 'authorization_code' || config("connect.clients.{$data['client_id']}") === null) {
            return response()->json(['error' => 'invalid_request', 'message' => 'Demande invalide.'], 400);
        }

        try {
            [$key, $plain, $merchant] = $this->connect->exchange($company, $data['client_id'], $data['code'], $data['code_verifier'], $data['redirect_uri']);
        } catch (ConnectException $e) {
            return response()->json(['error' => $e->error, 'message' => $e->getMessage()], 400);
        }

        return response()->json(['data' => [
            'api_key' => $plain,
            'scopes' => $key->scopes,
            'merchant' => ['id' => $merchant->id, 'business_name' => $merchant->business_name],
            'company' => ['name' => $company->name, 'slug' => $company->slug, 'url' => $company->url()],
        ]]);
    }

    private function company(): Company
    {
        $company = Branding::company();
        abort_if($company === null || ! $company->isActive(), 404, 'Aucune entreprise à cette adresse.');

        return $company;
    }
}
