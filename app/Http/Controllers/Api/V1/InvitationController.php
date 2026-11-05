<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Services\Accounts\Invitations;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;

/**
 * Lien de connexion de l'équipe et des livreurs : préparé par l'administrateur (envoyé depuis son WhatsApp), lu puis
 * ouvert par la personne (session ouverte, mot de passe facultatif). Les clients ont leurs propres comptes.
 */
class InvitationController extends Controller
{
    public function __construct(private readonly Invitations $invitations) {}

    public function store(Request $request, User $user): JsonResponse
    {
        // Un administrateur n'ouvre pas l'accès d'un autre administrateur
        Gate::authorize('changeCredentials', $user);
        abort_if($user->merchant_id !== null, 422, 'Les comptes clients se gèrent dans Clients › Comptes.');
        abort_if($request->user()->is($user), 422, 'Vous êtes déjà connecté.');
        abort_unless($user->status->value === 'active', 422, 'Réactivez d\'abord ce compte.');

        $invitation = $this->invitations->create($user, $request->user());
        UserAccountEvent::record($user, $request->user(), UserAccountEvent::INVITATION_SENT);

        return response()->json(['data' => $invitation], 201);
    }

    public function show(string $token): JsonResponse
    {
        $invitation = $this->invitations->find($token);
        abort_if($invitation === null, 404, 'Ce lien n\'est plus valable. Demandez-en un nouveau à votre administrateur.');
        $user = $invitation->user;

        return response()->json(['data' => [
            'name' => $user->name,
            'phone' => PhoneNumber::display($user->phone),
            'role_label' => $user->accessRole()?->displayLabel(),
            'company' => $user->company?->name,
            'space' => $user->space(),
            'expires_at' => $invitation->expires_at,
        ]]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = $this->invitations->find($token);
        abort_if($invitation === null, 404, 'Ce lien n\'est plus valable. Demandez-en un nouveau à votre administrateur.');
        $data = $request->validate([
            'password' => ['nullable', 'string', 'confirmed', Password::min(8)],
            'device_name' => ['nullable', 'string', 'max:100'],
        ], [], ['password' => 'mot de passe']);

        $user = $this->invitations->accept($invitation, $data['password'] ?? null);
        // Le lien remplace l'accès au compte : les sessions ouvertes ailleurs avant lui sont fermées
        $user->tokens()->delete();
        UserAccountEvent::record($user, $user, UserAccountEvent::INVITATION_USED);

        return response()->json(AuthController::session($user, $data['device_name'] ?? 'invitation'));
    }
}
