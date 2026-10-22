<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CompanyStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PlatformInvoice;
use App\Models\SupportSession;
use App\Models\User;
use App\Services\Onboarding\SetupGuide;
use App\Services\Platform\Billing;
use App\Services\Platform\CompanySignup;
use App\Services\Platform\Subscription;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Console du super administrateur : les entreprises de la plateforme, leur activité,
 * et l'ouverture tracée de leur espace pour l'assistance. La création et la
 * modification passent par /companies.
 */
class ConsoleController extends Controller
{
    public function companies(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(CompanyStatus::class)],
            'source' => ['nullable', Rule::in([CompanySignup::SOURCE])],
        ]);

        $companies = $this->withActivity(Company::query()->with('plan'))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($request->string('search')).'%'])
                ->orWhere('slug', 'like', '%'.mb_strtolower($request->string('search')).'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')))
            ->orderBy('name')
            ->get();

        $all = Company::query();

        return response()->json([
            'data' => $companies->map(fn (Company $c) => [...$this->companyData($c), 'setup' => $this->setupSummary($c)])->values(),
            'meta' => [
                'domain' => Tenancy::enabled() ? Tenancy::domain() : null,
                'reserved_subdomains' => config('platform.reserved_subdomains'),
                'totals' => [
                    'companies' => (clone $all)->count(),
                    'active' => (clone $all)->where('status', CompanyStatus::Active->value)->count(),
                    'orders_month' => (int) $companies->sum('orders_month_count'),
                    'on_trial' => $companies->filter(fn (Company $c) => $c->onTrial())->count(),
                    'signups_month' => (clone $all)->where('source', CompanySignup::SOURCE)->where('created_at', '>=', now()->startOfMonth())->count(),
                ],
            ],
        ]);
    }

    /**
     * @return array{done: int, total: int, ready: bool, alerts: int}
     */
    private function setupSummary(Company $company): array
    {
        $guide = app(SetupGuide::class)->for($company);

        return [...$guide['essentials'], 'ready' => $guide['ready'], 'alerts' => count(array_filter($guide['health'], fn ($h) => $h['level'] === 'alert'))];
    }

    public function show(Company $company, Subscription $subscription): JsonResponse
    {
        $company = $this->withActivity(Company::query()->with('plan'))->findOrFail($company->id);

        return response()->json(['data' => [
            ...$this->companyData($company),
            'phone' => $company->phone,
            'email' => $company->email,
            'address' => $company->address,
            'timezone' => $company->timezone,
            'domain' => Tenancy::enabled() ? Tenancy::domain() : null,
            'subscription' => $subscription->summary($company),
            // Mise en route de l'entreprise : étapes et bilan de santé (accompagnement au démarrage)
            'setup' => app(SetupGuide::class)->for($company),
            'billing' => app(Billing::class)->state($company),
            'invoices' => PlatformInvoice::query()->where('company_id', $company->id)->orderByDesc('period')->limit(24)->get()
                ->map(fn (PlatformInvoice $i) => ConsoleBillingController::invoiceData($i))->values(),
            // Comptes de l'équipe qui peuvent servir à l'assistance
            'staff' => User::query()->where('company_id', $company->id)->whereNull('merchant_id')
                ->whereHas('roles', fn ($q) => $q->whereIn('name', [Role::Admin->value, Role::Dispatcher->value, Role::Cashier->value, Role::HubAgent->value]))
                ->with('roles')->orderBy('name')->get()
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'phone' => $u->phone, 'role' => $u->roles->first()?->name, 'status' => $u->status]),
            'support_sessions' => SupportSession::query()->where('company_id', $company->id)->with(['opener', 'user'])->latest('id')->limit(20)->get()
                ->map(fn (SupportSession $s) => [
                    'id' => $s->id,
                    'opened_by' => $s->opener?->name,
                    'user' => $s->user?->name,
                    'reason' => $s->reason,
                    'created_at' => $s->created_at,
                    'expires_at' => $s->expires_at,
                ]),
        ]]);
    }

    /**
     * Ouvre l'espace de l'entreprise avec le compte d'un membre de son équipe (un
     * administrateur par défaut), pour une heure. L'ouverture est tracée.
     */
    public function supportSession(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $company->id)->whereNull('merchant_id')],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [], ['user_id' => 'compte', 'reason' => 'motif']);

        if (! $company->isActive()) {
            throw ValidationException::withMessages(['company' => 'Réactivez l\'entreprise pour ouvrir son espace.']);
        }

        $user = isset($data['user_id'])
            ? User::query()->find($data['user_id'])
            : User::query()->where('company_id', $company->id)->where('status', 'active')->role(Role::Admin->value)->orderBy('id')->first();

        if ($user === null || ! $user->isActive() || $user->hasRole(Role::Courier->value)) {
            throw ValidationException::withMessages(['user_id' => 'Aucun compte actif de l\'équipe à utiliser.']);
        }

        $expires = now()->addMinutes(SupportSession::MINUTES);
        $token = $user->createToken(SupportSession::TOKEN_PREFIX.$request->user()->id, ['*'], $expires)->plainTextToken;

        SupportSession::create([
            'company_id' => $company->id,
            'opened_by' => $request->user()->id,
            'user_id' => $user->id,
            'reason' => $data['reason'] ?? null,
            'ip' => $request->ip(),
            'expires_at' => $expires,
        ]);

        return response()->json(['data' => [
            // Le jeton est dans le fragment (#) : il n'est jamais envoyé au serveur ni journalisé
            'url' => $company->url('/admin#support='.$token),
            'user' => ['id' => $user->id, 'name' => $user->name],
            'expires_at' => $expires,
        ]], 201);
    }

    private function withActivity($query)
    {
        return $query->withCount([
            'merchants',
            'couriers',
            'orders as orders_total_count',
            'orders as orders_month_count' => fn ($q) => $q->where('created_at', '>=', now()->startOfMonth()),
        ])->withMax('orders as last_order_at', 'created_at');
    }

    /**
     * @return array<string, mixed>
     */
    private function companyData(Company $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'url' => $c->url(),
            // Domaine personnalisé (le serveur doit le connaître : voir docs/PLATEFORME.md)
            'custom_domain' => $c->custom_domain,
            'custom_domain_status' => $c->custom_domain_status,
            'status' => $c->status,
            'merchants_count' => $c->merchants_count,
            'couriers_count' => $c->couriers_count,
            'orders_total_count' => $c->orders_total_count,
            'orders_month_count' => $c->orders_month_count,
            'last_order_at' => $c->last_order_at,
            'plan' => $c->plan ? ['id' => $c->plan->id, 'name' => $c->plan->name] : null,
            'trial_ends_at' => $c->trial_ends_at?->toDateString(),
            'on_trial' => $c->onTrial(),
            // Inscrite seule en ligne (null : créée dans la console)
            'source' => $c->source,
            'created_at' => $c->created_at,
        ];
    }
}
