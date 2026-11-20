<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Mot de passe de la personne connectée (repris de Jibiat) : le définir (compte ouvert par WhatsApp, sans mot de
 * passe) ou le changer (mot de passe actuel demandé). Les autres appareils sont déconnectés au changement.
 */
class PasswordController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $hasPassword = $user->password !== null;

        $data = $request->validate([
            'current_password' => [$hasPassword ? 'required' : 'nullable', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ], [], ['current_password' => 'mot de passe actuel', 'password' => 'nouveau mot de passe']);

        if ($hasPassword && ! Hash::check($data['current_password'], $user->password)) {
            throw new BusinessRuleException('Le mot de passe actuel est incorrect.', 'current_password');
        }

        DB::transaction(function () use ($user, $data, $request) {
            $user->forceFill(['password' => $data['password']])->save();

            // Changement : les sessions ouvertes ailleurs sont fermées
            $current = $request->user()->currentAccessToken();
            $user->tokens()->when($current instanceof PersonalAccessToken, fn ($q) => $q->whereKeyNot($current->id))->delete();
        });

        return response()->json([
            'message' => $hasPassword ? 'Mot de passe changé.' : 'Mot de passe défini.',
            'user' => UserResource::make($user->fresh()->load(['company', 'merchant', 'courier', 'mechanic']))->withPermissions(),
        ]);
    }
}
