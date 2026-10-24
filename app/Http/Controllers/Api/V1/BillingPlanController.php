<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Activity;
use App\Enums\BillingFormula;
use App\Enums\DeliveryDelay;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BillingPlanResource;
use App\Models\BillingPlan;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\Finance\ClientSpace;
use App\Services\Orders\ActivityOptions;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Formules de facturation d'un client (distribution, MAD). Une seule en cours à la fois : en créer une
 * nouvelle termine la précédente la veille de son début.
 */
class BillingPlanController extends Controller
{
    /**
     * Tous les contrats (distribution, MAD) de son périmètre : en cours, à venir ou terminés, avec la
     * consommation du mois et la facture estimée des contrats en cours.
     */
    public function all(Request $request, ClientSpace $space): JsonResponse
    {
        abort_unless($request->user()->can(Permission::MerchantsView->value), 403);
        $data = $request->validate([
            'activity' => ['nullable', Rule::in([Activity::Distribution->value, Activity::Mad->value])],
            'state' => ['nullable', Rule::in(['current', 'upcoming', 'ended', 'all'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $today = CarbonImmutable::now($request->user()->company?->timezone ?: config('app.timezone'))->toDateString();
        $state = $data['state'] ?? 'current';

        $plans = BillingPlan::query()
            ->with(['merchant.company', 'couriers.user', 'zones'])
            ->when($data['activity'] ?? null, fn ($q, $a) => $q->where('activity', $a))
            ->when($state === 'current', fn ($q) => $q->activeOn($today))
            ->when($state === 'upcoming', fn ($q) => $q->whereDate('starts_on', '>', $today))
            ->when($state === 'ended', fn ($q) => $q->whereNotNull('ends_on')->whereDate('ends_on', '<', $today))
            ->when($data['search'] ?? null, fn ($q, $term) => $q->whereHas('merchant', fn ($m) => $m
                ->whereRaw('LOWER(business_name) LIKE ?', ['%'.mb_strtolower($term).'%'])))
            ->orderByDesc('starts_on')->orderByDesc('id')
            ->limit(500)->get()
            ->filter(fn (BillingPlan $p) => $request->user()->seesActivity($p->activity));

        $withAmounts = $request->user()->canAny([Permission::InvoicesView->value, Permission::RatesManage->value]);

        return response()->json(['data' => $plans->map(function (BillingPlan $p) use ($request, $space, $today, $withAmounts) {
            $current = $p->starts_on->toDateString() <= $today && ($p->ends_on === null || $p->ends_on->toDateString() >= $today);
            // Lot : son avancement (toute sa durée) ; contrat : le mois en cours
            $usage = $p->formula->isLot()
                ? ['id' => $p->id, 'month_label' => null, 'supplements' => 0, ...$space->lotProgress($p)]
                : ($current && $p->merchant ? $space->usage($p->merchant, $p->activity) : null);

            return [
                ...BillingPlanResource::make($p)->toArray($request),
                'activity' => $p->activity->value,
                'activity_label' => $p->activity->label(),
                'state' => $current ? 'current' : ($p->starts_on->toDateString() > $today ? 'upcoming' : 'ended'),
                'merchant' => $p->merchant ? ['id' => $p->merchant->id, 'business_name' => $p->merchant->business_name, 'phone' => $p->merchant->phone] : null,
                'usage' => $usage && $usage['id'] === $p->id ? [
                    'month_label' => $usage['month_label'],
                    'used' => $usage['used'],
                    'included' => $usage['included'],
                    'over' => $usage['over'],
                    'percent' => $usage['percent'],
                    'orders' => $usage['orders'],
                    'estimate' => $withAmounts ? $usage['estimate'] : null,
                    'supplements' => $withAmounts ? $usage['supplements'] : null,
                    // Lot : livrés, en cours, en retard, terminé
                    ...($p->formula->isLot() ? collect($usage)->only(['delivered', 'in_progress', 'late', 'done', 'remaining'])->all() : []),
                ] : null,
            ];
        })->values()]);
    }

    public function index(Request $request, Merchant $merchant): AnonymousResourceCollection
    {
        abort_unless($request->user()->can(Permission::MerchantsView->value), 403);

        return BillingPlanResource::collection($merchant->billingPlans()->with(['couriers.user', 'zones'])->get()
            ->filter(fn (BillingPlan $p) => $request->user()->seesActivity($p->activity))->values());
    }

    public function store(Request $request, Merchant $merchant): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $this->validated($request);

        $plan = DB::transaction(function () use ($merchant, $data) {
            $starts = CarbonImmutable::parse($data['starts_on']);
            $formula = BillingFormula::from($data['formula']);
            $activity = $formula->activity();
            // Le client a l'activité de la formule
            $merchant->syncActivities([...$merchant->activityList(), $activity]);
            // Contrat (pas un lot) de la même activité : le contrat en cours s'arrête la veille, un contrat qui n'a
            // pas commencé est remplacé. Un lot ponctuel s'ajoute aux autres, sans rien arrêter.
            if (! $formula->isLot()) {
                BillingPlan::where('merchant_id', $merchant->id)->where('activity', $activity->value)->contracts()->whereDate('starts_on', '>=', $starts)->get()
                    ->each(fn (BillingPlan $p) => Order::where('billing_plan_id', $p->id)->exists()
                        ? throw new BusinessRuleException('Une formule qui commence à cette date ou après a déjà des courses.', 'starts_on')
                        : $p->delete());
                BillingPlan::where('merchant_id', $merchant->id)->where('activity', $activity->value)->contracts()->activeOn($starts)
                    ->update(['ends_on' => $starts->subDay()->toDateString()]);
            }

            $plan = BillingPlan::create([
                'monthly_fee' => 0, 'included_quantity' => 0, 'overage_price' => 0, 'cheque_price' => 0, 'extra_kg_price' => 0,
                ...collect($data)->except(['courier_ids', 'zone_ids'])->all(),
                'merchant_id' => $merchant->id,
                'company_id' => $merchant->company_id,
            ]);
            $plan->couriers()->sync($plan->formula->isMad() ? ($data['courier_ids'] ?? []) : []);
            $plan->zones()->sync($plan->formula->hasForfait() ? ($data['zone_ids'] ?? []) : []);

            return $plan;
        });

        return BillingPlanResource::make($plan->load(['couriers.user', 'zones']))->response()->setStatusCode(201);
    }

    public function update(Request $request, BillingPlan $billingPlan): BillingPlanResource
    {
        $this->authorizeManage($request);
        $data = $this->validated($request, $billingPlan);

        // Formule déjà utilisée : ses prix restent ceux des courses passées (nouvelle formule pour les changer)
        $priced = ['formula', 'monthly_fee', 'included_quantity', 'overage_price', 'delivery_delay', 'cheque_price', 'extra_kg_price', 'starts_on', 'lot_quantity', 'lot_price'];
        $changed = collect($priced)->filter(fn ($f) => array_key_exists($f, $data)
            && (string) ($data[$f] ?? '') !== (string) ($billingPlan->getAttributes()[$f] ?? ''))->values();
        if ($changed->isNotEmpty() && Order::where('billing_plan_id', $billingPlan->id)->exists()) {
            throw new BusinessRuleException('Cette formule a déjà des courses : indiquez une date de fin et créez-en une nouvelle pour changer ses conditions.', $changed->first());
        }

        // Zones du forfait : modifiables à tout moment (les courses déjà créées gardent leur tarif)
        DB::transaction(function () use ($billingPlan, $data) {
            $billingPlan->update(collect($data)->except(['courier_ids', 'zone_ids'])->all());
            if (array_key_exists('zone_ids', $data)) {
                $billingPlan->zones()->sync($billingPlan->formula->hasForfait() ? $data['zone_ids'] : []);
            }
            if (array_key_exists('courier_ids', $data) || ! $billingPlan->formula->isMad()) {
                $billingPlan->couriers()->sync($billingPlan->formula->isMad() ? ($data['courier_ids'] ?? []) : []);
            }
        });

        return BillingPlanResource::make($billingPlan->fresh(['couriers.user', 'zones']));
    }

    public function destroy(Request $request, BillingPlan $billingPlan): JsonResponse
    {
        $this->authorizeManage($request);

        if (Order::where('billing_plan_id', $billingPlan->id)->exists()) {
            throw new BusinessRuleException('Cette formule a déjà des courses : indiquez plutôt une date de fin.', 'ends_on');
        }
        $billingPlan->delete();

        return response()->json(null, 204);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can(Permission::RatesManage->value), 403);
        $formula = BillingFormula::tryFrom((string) $request->input('formula', $request->route('billingPlan')?->formula?->value));
        abort_if($formula !== null && ! $request->user()->seesActivity($formula->activity()), 403, 'Vous ne gérez pas cette activité.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?BillingPlan $plan = null): array
    {
        $sometimes = $plan ? 'sometimes' : 'required';
        $formula = BillingFormula::tryFrom((string) $request->input('formula', $plan?->formula->value));
        $forfait = $formula?->hasForfait() ?? false;

        $data = $request->validate([
            'formula' => [$sometimes, Rule::enum(BillingFormula::class)],
            'monthly_fee' => [$forfait && ! $plan ? 'required' : 'sometimes', 'integer', 'min:0'],
            'included_quantity' => [$forfait && ! $plan ? 'required' : 'sometimes', 'integer', 'min:0'],
            'overage_price' => ['sometimes', 'integer', 'min:0'],
            'delivery_delay' => [$sometimes, Rule::enum(DeliveryDelay::class)],
            'cheque_price' => ['sometimes', 'integer', 'min:0'],
            'extra_kg_price' => ['sometimes', 'integer', 'min:0'],
            'internal_delay_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2000'],
            // Lot ponctuel : nombre de colis, prix global (vide : prix de la grille), date limite
            'lot_label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'lot_quantity' => [$formula?->isLot() && ! $plan ? 'required' : 'sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'lot_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'lot_due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            // Zones couvertes par le forfait : communes, villes de l'intérieur (vide : communes « Forfait »)
            'zone_ids' => ['sometimes', 'array', 'max:300'],
            'zone_ids.*' => ['integer', Rule::exists('zones', 'id')->where('company_id', $request->user()->company_id)],
            'starts_on' => [$sometimes, 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.($request->input('starts_on') ?? $plan?->starts_on?->toDateString() ?? '1970-01-01')],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'courier_ids' => ['sometimes', 'array', 'max:20'],
            'courier_ids.*' => ['integer', Rule::exists('couriers', 'id')->where('company_id', $request->user()->company_id)],
            // Réglages propres au contrat (preuve de remise, messages, réservation MAD) ; vide : ceux de l'entreprise
            ...ActivityOptions::rules('options'),
        ], [], [
            'formula' => 'formule',
            'monthly_fee' => 'forfait mensuel',
            'included_quantity' => 'quantité incluse',
            'overage_price' => 'prix au-delà du forfait',
            'delivery_delay' => 'délai',
            'cheque_price' => 'prix du chèque',
            'extra_kg_price' => 'prix du kilogramme en plus',
            'starts_on' => 'date de début',
            'ends_on' => 'date de fin',
            'courier_ids' => 'livreurs mis à disposition',
            'zone_ids' => 'zones du forfait',
            'internal_delay_hours' => 'délai interne',
            'lot_label' => 'nom du lot',
            'lot_quantity' => 'nombre de colis',
            'lot_price' => 'prix du lot',
            'lot_due_on' => 'date limite',
            'options.mad_from' => 'début de la mise à disposition',
            'options.mad_to' => 'fin de la mise à disposition',
        ]);
        if (array_key_exists('options', $data)) {
            $data['options'] = ActivityOptions::clean((array) ($data['options'] ?? [])) ?: null;
        }

        // Sans forfait : rien d'inclus
        if ($formula !== null && ! $forfait) {
            $data = [...$data, 'monthly_fee' => 0, 'included_quantity' => 0, 'overage_price' => 0];
        }
        // Pas un lot : pas de champs de lot
        if ($formula !== null && ! $formula->isLot()) {
            $data = [...$data, 'lot_label' => null, 'lot_quantity' => null, 'lot_price' => null, 'lot_due_on' => null];
        }

        return $data;
    }
}
