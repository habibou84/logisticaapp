<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PlatformFeature;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Plan;
use App\Rules\PhoneNumber;
use App\Services\Platform\CompanySignup;
use App\Support\PhoneNumber as Phone;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Inscription d'une entreprise de livraison en libre-service, depuis l'adresse de la
 * console (admin.jibiat.com/creer-mon-entreprise). À la fin, la session du nouvel
 * administrateur s'ouvre sur l'adresse de son entreprise.
 */
class CompanySignupController extends Controller
{
    public function __construct(private readonly CompanySignup $signup) {}

    /**
     * Inscriptions ouvertes ? Formules proposées, durée de l'essai, domaine.
     */
    public function options(): JsonResponse
    {
        $this->ensureConsoleHost();
        $open = $this->signup->isOpen();

        return response()->json(['data' => [
            'open' => $open,
            'domain' => Tenancy::enabled() ? Tenancy::domain() : null,
            'trial_days' => (int) config('platform.trial_days'),
            'plans' => $open ? $this->signup->plans()->map(fn (Plan $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'price_monthly' => $p->price_monthly,
                'included_orders' => $p->included_orders,
                'overage_per_order' => $p->overage_per_order,
                'max_couriers' => $p->max_couriers,
                'features' => collect($p->features ?? [])->map(fn (string $f) => PlatformFeature::tryFrom($f)?->label())->filter()->values(),
            ])->values() : [],
        ]]);
    }

    /**
     * Adresse libre ? (vérifiée pendant la saisie)
     */
    public function slug(Request $request): JsonResponse
    {
        $this->ensureConsoleHost();
        $request->validate(['slug' => ['required', 'string', 'max:60']]);

        return response()->json(['data' => $this->signup->slugStatus($request->string('slug'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureConsoleHost();

        // Champ invisible : seuls les robots le remplissent
        if (filled($request->input('website'))) {
            throw ValidationException::withMessages(['name' => 'Inscription refusée.']);
        }

        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => Phone::normalize($request->input('phone')) ?? $request->input('phone')]);
        }
        if (is_string($request->input('slug'))) {
            $request->merge(['slug' => mb_strtolower(trim($request->input('slug')))]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'slug' => ['required', ...Company::slugRules()],
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
            'admin_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', new PhoneNumber],
            'email' => ['nullable', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
            'accept_terms' => ['accepted'],
        ], [
            'slug.unique' => 'Cette adresse est déjà prise.',
            'slug.not_in' => 'Cette adresse est réservée.',
            'accept_terms.accepted' => 'Acceptez les conditions pour continuer.',
        ], [
            'name' => 'nom de l\'entreprise',
            'slug' => 'adresse',
            'plan_id' => 'formule',
            'admin_name' => 'votre nom',
            'phone' => 'téléphone',
            'password' => 'mot de passe',
        ]);

        [$company, $user] = $this->signup->register([
            'name' => trim($data['name']),
            'slug' => $data['slug'],
            'plan_id' => (int) $data['plan_id'],
            'admin_name' => trim($data['admin_name']),
            'phone' => $data['phone'],
            'email' => filled($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null,
            'password' => $data['password'],
        ], $request->ip());

        $token = $user->createToken('inscription')->plainTextToken;

        return response()->json(['data' => [
            'company' => ['id' => $company->id, 'name' => $company->name, 'slug' => $company->slug, 'trial_ends_at' => $company->trial_ends_at?->toDateString()],
            'url' => $company->url(),
            // Le jeton est dans le fragment (#) : jamais envoyé au serveur ni journalisé
            'login_url' => $company->url('/admin#session='.$token),
        ]], 201);
    }

    // L'inscription se fait depuis l'adresse de la console, pas depuis celle d'une entreprise
    private function ensureConsoleHost(): void
    {
        abort_unless(app(Tenancy::class)->zone() === Tenancy::CONSOLE, 404);
    }
}
