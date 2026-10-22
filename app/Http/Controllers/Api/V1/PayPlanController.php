<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PayBonusMetric;
use App\Enums\PayCalc;
use App\Enums\PayEvent;
use App\Enums\VehicleType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PayPlanResource;
use App\Models\Courier;
use App\Models\PayPlan;
use App\Models\Zone;
use App\Services\Finance\CourierPay;
use App\Services\Finance\PayPlanTemplates;
use App\Services\Finance\PaySimulator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Plans de rémunération des livreurs (Réglages › Paie des livreurs).
 */
class PayPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $plans = PayPlan::query()
            ->with(['rules', 'bonuses', 'owner.user'])
            ->withCount('couriers')
            ->orderByRaw('courier_id is not null')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => PayPlanResource::collection($plans)->resolve($request),
            'meta' => [
                'events' => collect(PayEvent::cases())->map(fn (PayEvent $e) => ['value' => $e->value, 'label' => $e->label()]),
                'calcs' => collect(PayCalc::cases())->map(fn (PayCalc $c) => ['value' => $c->value, 'label' => $c->label()]),
                'bonus_metrics' => collect(PayBonusMetric::cases())->map(fn (PayBonusMetric $m) => ['value' => $m->value, 'label' => $m->label()]),
                'periods' => collect(PayPlan::PERIODS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
                'templates' => collect(PayPlanTemplates::all())->map(fn ($t) => ['key' => $t['key'], 'name' => $t['name'], 'description' => $t['description']]),
            ],
        ]);
    }

    public function show(PayPlan $payPlan): PayPlanResource
    {
        return PayPlanResource::make($payPlan->load(['rules', 'bonuses', 'owner.user'])->loadCount('couriers'));
    }

    /**
     * Nouveau plan : depuis un modèle, en copie d'un plan existant, ou plan personnel
     * d'un livreur (copie du plan qu'il a aujourd'hui, puis attribué).
     */
    public function store(Request $request, CourierPay $pay): PayPlanResource
    {
        $companyId = $request->user()->company_id;
        $data = $request->validate([
            'name' => ['required_without:courier_id', 'nullable', 'string', 'max:100'],
            'template' => ['nullable', Rule::in(collect(PayPlanTemplates::all())->pluck('key'))],
            'copy_from' => ['nullable', 'integer', Rule::exists('pay_plans', 'id')->where('company_id', $companyId)],
            'courier_id' => ['nullable', 'integer', Rule::exists('couriers', 'id')->where('company_id', $companyId)],
        ]);

        $plan = DB::transaction(function () use ($data, $companyId, $pay) {
            $courier = isset($data['courier_id']) ? Courier::with(['user', 'payPlan'])->find($data['courier_id']) : null;
            if ($courier !== null && $courier->payPlan?->courier_id === $courier->id) {
                return $courier->payPlan; // déjà personnalisé
            }

            $source = match (true) {
                isset($data['copy_from']) => PayPlan::with(['rules', 'bonuses'])->find($data['copy_from']),
                $courier !== null => $pay->planFor($courier),
                default => null,
            };

            if ($source) {
                $attributes = $source->only(['pickup_mode', 'pickup_extra_parcel_amount', 'min_amount', 'max_amount', 'base_salary', 'pay_period', 'deduction_cap_percent']);
                $bonuses = $source->loadMissing('bonuses')->bonuses->map(fn ($b) => $b->only(['metric', 'threshold', 'min_count', 'amount', 'label']))
                    ->map(fn ($b) => ['metric' => $b['metric']->value] + $b)->all();
                $rules = $source->rules->map(fn ($r) => [
                    'event' => $r->event->value, 'calc' => $r->calc->value, 'amount' => $r->amount, 'percent' => $r->percent,
                    'zone_amounts' => $r->zone_amounts, 'conditions' => $r->conditions, 'label' => $r->label,
                ])->all();
            } else {
                $template = PayPlanTemplates::find($data['template'] ?? 'empty');
                [$attributes, $rules, $bonuses] = [$template['plan'], $template['rules'], $template['bonuses'] ?? []];
            }

            $plan = PayPlan::create([
                ...$attributes,
                'company_id' => $companyId,
                'courier_id' => $courier?->id,
                'name' => $data['name'] ?? 'Plan de '.$courier?->user?->name,
                'is_default' => false,
            ]);
            $this->syncRules($plan, $rules);
            $this->syncBonuses($plan, $bonuses);

            $courier?->update(['pay_plan_id' => $plan->id]);

            return $plan;
        });

        return $this->show($plan);
    }

    public function update(Request $request, PayPlan $payPlan): PayPlanResource
    {
        $companyId = $payPlan->company_id;
        $data = $this->validatePlan($request, $companyId);

        $this->ensurePeriod(
            array_key_exists('pay_period', $data) ? $data['pay_period'] : $payPlan->pay_period,
            $data['base_salary'] ?? $payPlan->base_salary,
            array_key_exists('bonuses', $data) ? count($data['bonuses']) : $payPlan->bonuses()->count(),
        );
        if (($data['is_default'] ?? false) && $payPlan->courier_id !== null) {
            throw ValidationException::withMessages(['is_default' => 'Un plan personnel ne peut pas être le plan par défaut.']);
        }
        if (array_key_exists('is_default', $data) && ! $data['is_default'] && $payPlan->is_default) {
            throw ValidationException::withMessages(['is_default' => 'Choisissez un autre plan par défaut : il en faut toujours un.']);
        }
        DB::transaction(function () use ($payPlan, $data) {
            if ($data['is_default'] ?? false) {
                PayPlan::forCompany($payPlan->company_id)->where('id', '!=', $payPlan->id)->update(['is_default' => false]);
            }
            $payPlan->update(collect($data)->except(['rules', 'bonuses'])->all());
            if (array_key_exists('rules', $data)) {
                $this->syncRules($payPlan, $data['rules']);
            }
            if (array_key_exists('bonuses', $data)) {
                $this->syncBonuses($payPlan, $data['bonuses']);
            }
        });

        return $this->show($payPlan->refresh());
    }

    /**
     * Simulation avec les réglages de l'éditeur (même non enregistrés) : une course
     * fictive (scenario), ou l'activité réelle d'une période passée (replay).
     */
    public function simulate(Request $request, PaySimulator $simulator): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $data = $this->validatePlan($request, $companyId, simulation: true);
        $input = $request->validate([
            'mode' => ['required', Rule::in(['scenario', 'replay'])],
            'scenario' => ['required_if:mode,scenario', 'array'],
            'scenario.event' => ['required_if:mode,scenario', Rule::enum(PayEvent::class)],
            'scenario.zone_id' => ['nullable', 'integer', Rule::exists('zones', 'id')->where('company_id', $companyId)],
            'scenario.fees' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'scenario.collected' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'scenario.express' => ['nullable', 'boolean'],
            'scenario.fragile' => ['nullable', 'boolean'],
            'scenario.vehicle_type' => ['nullable', Rule::enum(VehicleType::class)],
            'scenario.at' => ['nullable', 'date'],
            'scenario.incident_reason_id' => ['nullable', 'integer'],
            'scenario.same_visit' => ['nullable', 'boolean'],
            // Rejeu : la période de paie du plan qui contient cette date (le mois si « à la demande »)
            'date' => ['required_if:mode,replay', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], [], ['date' => 'date', 'scenario.event' => 'étape']);

        $plan = $simulator->plan($companyId, $data);

        if ($input['mode'] === 'scenario') {
            return response()->json(['data' => $simulator->scenario($plan, $input['scenario'])]);
        }

        $date = CarbonImmutable::parse($input['date']);
        [$start, $end] = $plan->periodContaining($date) ?? [$date->startOfMonth(), $date->endOfMonth()->startOfDay()];

        return response()->json(['data' => $simulator->replay($plan, $start, min($end, CarbonImmutable::today()))]);
    }

    /**
     * Suppression : les livreurs du plan reviennent au plan par défaut. Les gains
     * déjà acquis gardent leurs montants.
     */
    public function destroy(PayPlan $payPlan): Response
    {
        if ($payPlan->is_default) {
            throw ValidationException::withMessages(['plan' => 'Le plan par défaut ne peut pas être supprimé : choisissez d\'abord un autre plan par défaut.']);
        }

        DB::transaction(function () use ($payPlan) {
            Courier::forCompany($payPlan->company_id)->where('pay_plan_id', $payPlan->id)->update(['pay_plan_id' => null]);
            $payPlan->delete();
        });

        return response()->noContent();
    }

    /**
     * Réglages d'un plan (enregistrement ou simulation).
     *
     * @return array<string, mixed>
     */
    private function validatePlan(Request $request, int $companyId, bool $simulation = false): array
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'is_default' => [$simulation ? 'exclude' : 'sometimes', 'boolean'],
            'pickup_mode' => ['sometimes', Rule::in([PayPlan::PICKUP_PER_PARCEL, PayPlan::PICKUP_PER_VISIT])],
            'pickup_extra_parcel_amount' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'min_amount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'max_amount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000', 'gte:min_amount'],
            'base_salary' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'pay_period' => ['sometimes', 'nullable', Rule::in(array_keys(PayPlan::PERIODS))],
            'deduction_cap_percent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'rules' => ['sometimes', 'array', 'max:100'],
            'rules.*.event' => ['required', Rule::enum(PayEvent::class)],
            'rules.*.calc' => ['required', Rule::enum(PayCalc::class)],
            'rules.*.amount' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            // Pourcentage et grille ne comptent que pour leur calcul : ignorés sinon
            'rules.*.percent' => ['exclude_unless:rules.*.calc,percent_fee,percent_collected', 'required', 'numeric', 'min:0', 'max:100'],
            'rules.*.zone_amounts' => ['exclude_unless:rules.*.calc,zone_grid', 'nullable', 'array'],
            'rules.*.zone_amounts.*' => ['integer', 'min:0', 'max:1000000'],
            'rules.*.label' => ['nullable', 'string', 'max:100'],
            'rules.*.conditions' => ['nullable', 'array'],
            'rules.*.conditions.zone_ids' => ['nullable', 'array'],
            'rules.*.conditions.zone_ids.*' => ['integer', Rule::exists('zones', 'id')->where('company_id', $companyId)],
            'rules.*.conditions.express' => ['nullable', 'boolean'],
            'rules.*.conditions.fragile' => ['nullable', 'boolean'],
            'rules.*.conditions.vehicle_types' => ['nullable', 'array'],
            'rules.*.conditions.vehicle_types.*' => [Rule::enum(VehicleType::class)],
            'rules.*.conditions.incident_reason_ids' => ['nullable', 'array'],
            'rules.*.conditions.incident_reason_ids.*' => ['integer', Rule::exists('incident_reasons', 'id')->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))],
            // Jours (1 = lundi … 7 = dimanche) et plage horaire, heure de l'entreprise
            'rules.*.conditions.days' => ['nullable', 'array'],
            'rules.*.conditions.days.*' => ['integer', 'between:1,7'],
            'rules.*.conditions.time_from' => ['nullable', 'required_with:rules.*.conditions.time_to', 'date_format:H:i'],
            'rules.*.conditions.time_to' => ['nullable', 'required_with:rules.*.conditions.time_from', 'date_format:H:i', 'different:rules.*.conditions.time_from'],
            'bonuses' => ['sometimes', 'array', 'max:50'],
            'bonuses.*.metric' => ['required', Rule::enum(PayBonusMetric::class)],
            'bonuses.*.threshold' => ['required', 'integer', 'min:1', 'max:1000000'],
            'bonuses.*.min_count' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'bonuses.*.amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'bonuses.*.label' => ['nullable', 'string', 'max:100'],
        ], [], [
            'rules.*.event' => 'étape',
            'rules.*.calc' => 'calcul',
            'rules.*.amount' => 'montant',
            'rules.*.percent' => 'pourcentage',
            'rules.*.zone_amounts' => 'grille par zone',
            'rules.*.zone_amounts.*' => 'montant de la zone',
            'rules.*.label' => 'libellé',
            'rules.*.conditions.zone_ids.*' => 'zone',
            'rules.*.conditions.vehicle_types.*' => 'véhicule',
            'rules.*.conditions.incident_reason_ids.*' => 'motif d\'échec',
            'rules.*.conditions.days.*' => 'jour',
            'rules.*.conditions.time_from' => 'heure de début',
            'rules.*.conditions.time_to' => 'heure de fin',
            'bonuses.*.metric' => 'objectif',
            'bonuses.*.threshold' => 'seuil',
            'bonuses.*.min_count' => 'nombre minimal de livraisons',
            'bonuses.*.amount' => 'montant de la prime',
            'bonuses.*.label' => 'libellé',
            'pickup_extra_parcel_amount' => 'montant par colis supplémentaire',
            'min_amount' => 'minimum',
            'max_amount' => 'plafond',
            'base_salary' => 'salaire de base',
            'pay_period' => 'période de paie',
            'deduction_cap_percent' => 'plafond des retenues',
        ]);

        foreach ($data['rules'] ?? [] as $i => $rule) {
            $zones = array_keys($rule['zone_amounts'] ?? []);
            if ($zones && Zone::forCompany($companyId)->whereIn('id', $zones)->count() !== count($zones)) {
                throw ValidationException::withMessages(["rules.$i.zone_amounts" => 'Zone inconnue dans la grille.']);
            }
        }
        foreach ($data['bonuses'] ?? [] as $i => $bonus) {
            if ($bonus['metric'] === PayBonusMetric::SuccessRate->value && $bonus['threshold'] > 100) {
                throw ValidationException::withMessages(["bonuses.$i.threshold" => 'Un taux de réussite ne dépasse pas 100 %.']);
            }
        }

        return $data;
    }

    /**
     * Salaire et primes se calculent par période : il en faut une.
     */
    private function ensurePeriod(?string $period, int $salary, int $bonuses): void
    {
        if ($period !== null) {
            return;
        }
        if ($salary > 0) {
            throw ValidationException::withMessages(['pay_period' => 'Choisissez la période de paie : le salaire de base est versé à chaque période.']);
        }
        if ($bonuses > 0) {
            throw ValidationException::withMessages(['pay_period' => 'Choisissez la période de paie : les primes d\'objectifs se calculent sur chaque période.']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $bonuses
     */
    private function syncBonuses(PayPlan $plan, array $bonuses): void
    {
        $plan->bonuses()->delete();
        foreach (array_values($bonuses) as $i => $bonus) {
            $plan->bonuses()->create([
                'metric' => $bonus['metric'],
                'threshold' => $bonus['threshold'],
                'min_count' => $bonus['metric'] === PayBonusMetric::SuccessRate->value ? ($bonus['min_count'] ?? null) : null,
                'amount' => $bonus['amount'],
                'label' => $bonus['label'] ?? null,
                'sort_order' => $i,
            ]);
        }
        $plan->unsetRelation('bonuses');
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    private function syncRules(PayPlan $plan, array $rules): void
    {
        $plan->rules()->delete();

        foreach (array_values($rules) as $i => $rule) {
            $conditions = array_filter($rule['conditions'] ?? [], fn ($v) => $v !== null && $v !== false && $v !== []);
            $plan->rules()->create([
                'event' => $rule['event'],
                'calc' => $rule['calc'],
                'amount' => (int) ($rule['amount'] ?? 0),
                'percent' => in_array($rule['calc'], [PayCalc::PercentFee->value, PayCalc::PercentCollected->value], true) ? $rule['percent'] ?? 0 : null,
                'zone_amounts' => $rule['calc'] === PayCalc::ZoneGrid->value ? array_map('intval', $rule['zone_amounts'] ?? []) : null,
                'conditions' => $conditions ?: null,
                'label' => $rule['label'] ?? null,
                'sort_order' => $i,
            ]);
        }

        $plan->unsetRelation('rules');
    }
}
