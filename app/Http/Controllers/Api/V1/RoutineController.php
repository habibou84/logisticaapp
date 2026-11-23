<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Activity;
use App\Enums\BillingFormula;
use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\BillingPlan;
use App\Models\CourierRoutine;
use App\Services\Orders\ActivityOptions;
use App\Services\Shifts\Routines;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Parcours des livreurs en MAD : par l'équipe (fiche du contrat) et par le client (son espace « Mes parcours »).
 * Les changements du client valent tout de suite ; chaque parcours garde qui l'a modifié en dernier.
 */
class RoutineController extends Controller
{
    public function __construct(private readonly Routines $routines) {}

    // ——— Équipe ———

    /** Page Livraison › Parcours : les contrats de mise à disposition en cours, avec le nombre de parcours */
    public function contracts(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->merchant_id === null && ! $user->isCourier()
            && $user->canAny([Permission::RatesManage->value, Permission::OrdersDispatch->value, Permission::MerchantsView->value]), 403);
        abort_unless($user->seesActivity(Activity::Mad), 403, 'Vous ne gérez pas cette activité.');
        $today = CarbonImmutable::now($user->company?->timezone ?: config('app.timezone'))->toDateString();

        $plans = BillingPlan::query()->where('activity', Activity::Mad->value)->activeOn($today)
            ->with(['merchant:id,business_name', 'couriers.user:id,name'])->withCount('routines')->get()
            ->sortBy(fn (BillingPlan $p) => mb_strtolower((string) $p->merchant?->business_name))->values();

        return response()->json([
            'data' => $plans->map(fn (BillingPlan $p) => [
                'id' => $p->id,
                'merchant' => $p->merchant?->business_name,
                'couriers' => $p->couriers->map(fn ($c) => $c->user?->name)->filter()->values(),
                'routines_count' => $p->routines_count,
            ]),
            'can_edit' => $user->canAny([Permission::RatesManage->value, Permission::OrdersDispatch->value]),
        ]);
    }

    public function index(Request $request, BillingPlan $billingPlan): JsonResponse
    {
        $this->authorizeStaff($request, $billingPlan);

        return response()->json(['data' => $this->routines->list($billingPlan), 'plan' => $this->plan($billingPlan)]);
    }

    public function store(Request $request, BillingPlan $billingPlan): JsonResponse
    {
        $this->authorizeStaff($request, $billingPlan);
        $this->routines->ensureMad($billingPlan);
        $data = $request->validate(Routines::rules($billingPlan), [], Routines::attributes());

        $routine = $this->routines->save($billingPlan, null, $data, $request->user(), 'staff');

        return response()->json(['data' => $this->routines->present($routine)], 201);
    }

    public function update(Request $request, CourierRoutine $routine): JsonResponse
    {
        $plan = $routine->billingPlan;
        $this->authorizeStaff($request, $plan);
        $data = $request->validate(Routines::rules($plan), [], Routines::attributes());

        return response()->json(['data' => $this->routines->present($this->routines->save($plan, $routine, $data, $request->user(), 'staff'))]);
    }

    public function destroy(Request $request, CourierRoutine $routine): JsonResponse
    {
        $this->authorizeStaff($request, $routine->billingPlan);
        $routine->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** Lieux de l'annuaire pour une étape (équipe et client) */
    public function places(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->isCourier(), 403);
        $data = $request->validate(['q' => ['required', 'string', 'max:100']]);

        return response()->json(['data' => $this->routines->searchPlaces($user->company_id, $data['q'])]);
    }

    // ——— Client ———

    public function mine(Request $request): JsonResponse
    {
        $plan = $this->merchantPlan($request);

        return response()->json([
            'data' => $plan ? $this->routines->list($plan) : [],
            'plan' => $plan ? $this->plan($plan) : null,
            'can_edit' => $this->merchantCanEdit($request),
        ]);
    }

    public function storeMine(Request $request): JsonResponse
    {
        $plan = $this->editablePlan($request);
        $data = $request->validate(Routines::rules($plan), [], Routines::attributes());

        $routine = $this->routines->save($plan, null, $data, $request->user(), 'merchant');

        return response()->json(['data' => $this->routines->present($routine)], 201);
    }

    public function updateMine(Request $request, CourierRoutine $routine): JsonResponse
    {
        $plan = $this->editablePlan($request);
        abort_unless($routine->billing_plan_id === $plan->id, 404);
        $data = $request->validate(Routines::rules($plan), [], Routines::attributes());

        return response()->json(['data' => $this->routines->present($this->routines->save($plan, $routine, $data, $request->user(), 'merchant'))]);
    }

    public function destroyMine(Request $request, CourierRoutine $routine): JsonResponse
    {
        $plan = $this->editablePlan($request);
        abort_unless($routine->billing_plan_id === $plan->id, 404);
        $routine->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Contrat pour l'écran : livreurs, jours et heure de début de la MAD, siège du client.
     *
     * @return array<string, mixed>
     */
    private function plan(BillingPlan $plan): array
    {
        $plan->loadMissing(['couriers.user', 'merchant']);
        $options = app(ActivityOptions::class)->plan($plan);

        return [
            'id' => $plan->id,
            'merchant' => $plan->merchant?->business_name,
            'merchant_located' => (bool) $plan->merchant?->hasPickupLocation(),
            'mad_days' => array_map('intval', $options['mad_days'] ?? []),
            'mad_from' => $options['mad_from'] ?? null,
            'couriers' => $plan->couriers->map(fn ($c) => ['id' => $c->id, 'name' => $c->user?->name])->values()->all(),
        ];
    }

    private function authorizeStaff(Request $request, BillingPlan $plan): void
    {
        $user = $request->user();
        abort_unless($user->merchant_id === null && ! $user->isCourier()
            && $user->canAny([Permission::RatesManage->value, Permission::OrdersDispatch->value, Permission::MerchantsView->value]), 403);
        abort_unless($user->seesActivity($plan->activity), 403, 'Vous ne gérez pas cette activité.');
        if ($request->isMethod('GET')) {
            return;
        }
        abort_unless($user->canAny([Permission::RatesManage->value, Permission::OrdersDispatch->value]), 403);
    }

    private function merchantPlan(Request $request): ?BillingPlan
    {
        $merchant = $request->user()->merchant;
        abort_if($merchant === null, 403, 'Réservé aux clients.');
        $plan = BillingPlan::activeFor($merchant, Activity::Mad);

        return $plan?->formula === BillingFormula::MadContract ? $plan : null;
    }

    private function merchantCanEdit(Request $request): bool
    {
        return $request->user()->hasAnyRole([Role::MerchantOwner->value, Role::MerchantManager->value]);
    }

    private function editablePlan(Request $request): BillingPlan
    {
        $plan = $this->merchantPlan($request);
        abort_if($plan === null, 422, 'Aucun contrat de mise à disposition en cours.');
        abort_unless($this->merchantCanEdit($request), 403, 'Seuls le responsable du compte et les gérants modifient les parcours.');

        return $plan;
    }
}
