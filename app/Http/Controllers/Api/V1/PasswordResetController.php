<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Services\Accounts\PhoneVerifier;
use App\Support\Branding;
use App\Support\PhoneNumber as Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * Mot de passe oublié : le numéro est vérifié (WhatsApp ou SMS) puis la personne
 * choisit un nouveau mot de passe ; ses autres appareils sont déconnectés.
 * La réponse est la même que le numéro ait un compte ou non.
 */
class PasswordResetController extends Controller
{
    public function __construct(private readonly PhoneVerifier $verifier) {}

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', new PhoneNumber]], [], ['phone' => 'téléphone']);
        $phone = Phone::normalize($data['phone']);

        // Plusieurs entreprises sur une seule adresse (sans plateforme) : celle de la page d'accueil d'abord
        $company = Branding::company();
        $users = User::loginableHere()->where('phone', $phone)->with('company')->get();
        $user = $users->count() > 1 ? ($users->firstWhere('company_id', $company?->id) ?? $users->first()) : $users->first();
        $user = $user?->fresh();
        $company = $user?->company ?? $company;
        abort_if($company === null, 404);

        [$verification, $token] = $this->verifier->start($company, PhoneVerification::PASSWORD_RESET, $phone, $user, null, $request->ip());

        return response()->json(['token' => $token, 'data' => VerificationController::describe($this->verifier, $verification)], 201);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ], [], ['password' => 'mot de passe']);

        $verification = $this->verifier->find($data['token']);

        if ($verification === null || $verification->purpose !== PhoneVerification::PASSWORD_RESET
            || ! $verification->isVerified() || $verification->completed_at !== null || $verification->isExpired()) {
            throw new BusinessRuleException('Cette demande a expiré. Recommencez.');
        }

        $user = $verification->user;
        if ($user === null) {
            throw new BusinessRuleException("Aucun compte n'est associé à ce numéro.");
        }

        if (! $user->isActive()) {
            throw new BusinessRuleException('Compte suspendu. Contactez votre administrateur.');
        }

        DB::transaction(function () use ($verification, $user, $data) {
            $verification->forceFill(['completed_at' => now()])->save();
            $user->forceFill([
                'password' => $data['password'],
                'phone_verified_at' => $user->phone_verified_at ?? now(),
            ])->save();
            // Les sessions ouvertes ailleurs (téléphone perdu, mot de passe connu d'un tiers) sont fermées
            $user->tokens()->delete();
        });

        return response()->json(AuthController::session($user->fresh(), 'nouveau-mot-de-passe'));
    }
}
