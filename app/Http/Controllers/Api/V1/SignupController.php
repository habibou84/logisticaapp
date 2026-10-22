<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Models\Zone;
use App\Rules\PhoneNumber;
use App\Services\Accounts\PhoneVerifier;
use App\Support\Branding;
use App\Support\PhoneNumber as Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Inscription en ligne d'un e-commerçant (page d'accueil du site). Le compte n'est
 * créé qu'une fois le numéro vérifié (voir VerificationController), puis il est
 * actif aussitôt.
 */
class SignupController extends Controller
{
    public function __construct(private readonly PhoneVerifier $verifier) {}

    /**
     * Inscriptions ouvertes ? Zones de ramassage proposées.
     */
    public function options(): JsonResponse
    {
        $company = Branding::company();
        $open = (bool) $company?->merchant_signup;

        return response()->json(['data' => [
            'open' => $open,
            'zones' => $open
                ? Zone::forCompany($company->id)->active()->where('is_shipping', false)->with('parent')->orderBy('name')->get()
                    ->map(fn (Zone $z) => ['id' => $z->id, 'name' => $z->parent ? "{$z->name} ({$z->parent->name})" : $z->name])
                    ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()
                : [],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $this->openCompany();

        foreach (['phone'] as $key) {
            if (is_string($request->input($key))) {
                $request->merge([$key => Phone::normalize($request->input($key)) ?? $request->input($key)]);
            }
        }

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', new PhoneNumber],
            'email' => ['nullable', 'email', 'max:255'],
            'pickup_zone_id' => ['required', 'integer', Rule::exists('zones', 'id')->where(fn ($q) => $q->where('company_id', $company->id)->where('is_active', true)->where('is_shipping', false))],
            'pickup_address' => ['required', 'string', 'max:500'],
            'pickup_landmark' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ], [], [
            'business_name' => 'nom de la boutique',
            'contact_name' => 'nom du responsable',
            'phone' => 'téléphone',
            'pickup_zone_id' => 'commune de ramassage',
            'pickup_address' => 'adresse de ramassage',
            'pickup_landmark' => 'repère',
            'password' => 'mot de passe',
        ]);

        if (User::takenIn($company->id, 'phone', $data['phone'])
            || Merchant::forCompany($company->id)->where('phone', $data['phone'])->exists()) {
            throw new BusinessRuleException('Ce numéro a déjà un compte. Connectez-vous, ou utilisez « Mot de passe oublié ».', 'phone');
        }

        if (User::takenIn($company->id, 'email', filled($data['email'] ?? null) ? mb_strtolower($data['email']) : null)) {
            throw new BusinessRuleException('Cette adresse e-mail est déjà utilisée.', 'email');
        }

        [$verification, $token] = $this->verifier->start($company, PhoneVerification::SIGNUP, $data['phone'], null, [
            'business_name' => trim($data['business_name']),
            'contact_name' => trim($data['contact_name']),
            'email' => filled($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null,
            'pickup_zone_id' => $data['pickup_zone_id'],
            'pickup_address' => trim($data['pickup_address']),
            'pickup_landmark' => filled($data['pickup_landmark'] ?? null) ? trim($data['pickup_landmark']) : null,
            'password_hash' => Hash::make($data['password']),
        ], $request->ip());

        return response()->json(['token' => $token, 'data' => VerificationController::describe($this->verifier, $verification)], 201);
    }

    private function openCompany(): Company
    {
        $company = Branding::company();

        if (! $company?->merchant_signup) {
            throw new BusinessRuleException('Les inscriptions en ligne sont fermées. Contactez-nous pour ouvrir un compte.');
        }

        return $company;
    }
}
