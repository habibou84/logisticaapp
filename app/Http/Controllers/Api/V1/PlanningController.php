<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CourierAbsence;
use App\Models\CourierRule;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Models\Zone;
use App\Services\Assignment\CourierPlanning;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Planning des livreurs (dispatch) : règles « qui ramasse ou livre où et quand », absences,
 * et vue d'un jour (qui couvre quoi, clients sans personne).
 */
class PlanningController extends Controller
{
    public function __construct(private readonly CourierPlanning $planning) {}

    /**
     * Un jour : règles (s'appliquent-elles ? qui couvre, titulaire ou remplaçant), absences, clients sans personne.
     */
    public function show(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date']]);
        $company = $request->user()->company;
        $date = $request->date('date')?->toDateString()
            ?? CarbonImmutable::now($company->timezone ?: config('app.timezone'))->toDateString();
        $absent = $this->planning->absentOn($company->id, $date);

        $rules = CourierRule::query()->with(['courier.user.company', 'backup.user.company'])->orderBy('type')->orderBy('id')->get()
            ->map(fn (CourierRule $r) => $this->ruleData($r, $date, $absent));

        $absences = CourierAbsence::query()->with('courier.user')
            ->whereDate('ends_on', '>=', CarbonImmutable::parse($date)->subDays(7)->toDateString())
            ->orderBy('starts_on')->get()
            ->map(fn (CourierAbsence $a) => [
                'id' => $a->id,
                'courier' => ['id' => $a->courier_id, 'name' => $a->courier?->user?->name],
                'starts_on' => $a->starts_on->toDateString(),
                'ends_on' => $a->ends_on->toDateString(),
                'reason' => $a->reason,
                'current' => $a->starts_on->toDateString() <= $date && $a->ends_on->toDateString() >= $date,
            ]);

        return response()->json(['data' => [
            'date' => $date,
            'auto_pickup' => $company->auto_pickup ?? 'off',
            'auto_delivery' => $company->auto_delivery ?? 'off',
            'automation' => $this->automation($company),
            'rules' => $rules,
            'absences' => $absences,
            'gaps' => $this->gaps($company->id, $date),
        ]]);
    }

    /**
     * Suspendre (ou reprendre) l'automatisation : plus d'assignation ni de passage au remplaçant, les alertes restent.
     */
    public function pause(Request $request): JsonResponse
    {
        $data = $request->validate(['paused' => ['required', 'boolean']]);
        $company = $request->user()->company;
        $company->forceFill($data['paused']
            ? ['automation_paused_at' => now(), 'automation_paused_by' => $request->user()->id]
            : ['automation_paused_at' => null, 'automation_paused_by' => null])->save();

        return response()->json(['data' => $this->automation($company->fresh())]);
    }

    /**
     * @return array{paused: bool, paused_at: ?string, paused_by: ?string}
     */
    private function automation(Company $company): array
    {
        return [
            'paused' => $company->automationPaused(),
            'paused_at' => $company->automation_paused_at?->toIso8601String(),
            'paused_by' => $company->automation_paused_by ? User::query()->find($company->automation_paused_by)?->name : null,
        ];
    }

    public function storeRule(Request $request): JsonResponse
    {
        $data = $this->validatedRule($request);
        $rule = CourierRule::create([...$data, 'created_by' => $request->user()->id]);

        return response()->json(['data' => $this->ruleData($rule->load(['courier.user.company', 'backup.user.company']))], 201);
    }

    public function updateRule(Request $request, CourierRule $rule): JsonResponse
    {
        $rule->update($this->validatedRule($request, $rule));

        return response()->json(['data' => $this->ruleData($rule->fresh(['courier.user.company', 'backup.user.company']))]);
    }

    public function destroyRule(CourierRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->json(null, 204);
    }

    public function storeAbsence(Request $request): JsonResponse
    {
        $data = $request->validate([
            'courier_id' => ['required', 'integer', Rule::exists('couriers', 'id')->where('company_id', $request->user()->company_id)],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:120'],
        ], [], ['courier_id' => 'livreur', 'starts_on' => 'début', 'ends_on' => 'fin', 'reason' => 'motif']);
        $absence = CourierAbsence::create([...$data, 'created_by' => $request->user()->id]);

        return response()->json(['data' => ['id' => $absence->id]], 201);
    }

    public function destroyAbsence(CourierAbsence $absence): JsonResponse
    {
        $absence->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedRule(Request $request, ?CourierRule $rule = null): array
    {
        $companyId = $request->user()->company_id;
        $courier = Rule::exists('couriers', 'id')->where('company_id', $companyId);
        $data = $request->validate([
            'type' => ['required', Rule::in([CourierRule::PICKUP, CourierRule::DELIVERY])],
            'courier_id' => ['required', 'integer', $courier],
            'backup_courier_id' => ['nullable', 'integer', 'different:courier_id', $courier],
            'zone_ids' => ['nullable', 'array', 'required_without:merchant_ids'],
            'zone_ids.*' => ['integer', Rule::exists('zones', 'id')->where('company_id', $companyId)],
            'merchant_ids' => ['nullable', 'array', 'required_without:zone_ids'],
            'merchant_ids.*' => ['integer', Rule::exists('merchants', 'id')->where('company_id', $companyId)],
            'days' => ['nullable', 'array'],
            'days.*' => ['integer', 'between:1,7'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'is_active' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:160'],
        ], [], [
            'courier_id' => 'livreur', 'backup_courier_id' => 'remplaçant', 'zone_ids' => 'zones', 'merchant_ids' => 'clients',
            'days' => 'jours', 'starts_on' => 'début', 'ends_on' => 'fin',
        ]);

        if (! empty($data['zone_ids']) && ! empty($data['merchant_ids'])) {
            throw new BusinessRuleException('Une règle vise soit des zones, soit des clients : créez deux règles.', 'merchant_ids');
        }
        // Une seule cible ; liste vide = rien
        $data['zone_ids'] = ! empty($data['merchant_ids']) ? null : array_values(array_unique(array_map('intval', $data['zone_ids'] ?? [])));
        $data['merchant_ids'] = ! empty($data['merchant_ids']) ? array_values(array_unique(array_map('intval', $data['merchant_ids']))) : null;
        $data['days'] = ! empty($data['days']) ? array_values(array_unique(array_map('intval', $data['days']))) : null;

        if (($data['is_active'] ?? true) && ($conflicts = $this->planning->conflicts($companyId, $data, $rule?->id))) {
            throw new BusinessRuleException('Cette règle contredit une autre règle pour les mêmes jours : '.implode(' ; ', $conflicts).'. Choisissez des jours différents, ou une date précise pour un remplacement.', 'courier_id');
        }

        return $data;
    }

    /**
     * @param  list<int>|null  $absent
     * @return array<string, mixed>
     */
    private function ruleData(CourierRule $rule, ?string $date = null, ?array $absent = null): array
    {
        $applies = $date !== null && $rule->appliesOn($date);
        $covering = null;
        if ($applies) {
            $covering = $this->planning->available($rule->courier, $absent ?? [])
                ? 'courier'
                : ($rule->backup && $this->planning->available($rule->backup, $absent ?? []) ? 'backup' : 'none');
        }

        return [
            'id' => $rule->id,
            'type' => $rule->type,
            'courier' => ['id' => $rule->courier_id, 'name' => $rule->courier?->user?->name],
            'backup' => $rule->backup_courier_id ? ['id' => $rule->backup_courier_id, 'name' => $rule->backup?->user?->name] : null,
            'zone_ids' => $rule->zone_ids ?? [],
            'merchant_ids' => $rule->merchant_ids ?? [],
            'days' => $rule->days ?? [],
            'starts_on' => $rule->starts_on?->toDateString(),
            'ends_on' => $rule->ends_on?->toDateString(),
            'is_active' => $rule->is_active,
            'note' => $rule->note,
            'label' => $this->planning->describe($rule),
            'when' => $this->planning->when($rule),
            // Ce jour-là : la règle s'applique-t-elle, et qui couvre (titulaire, remplaçant, personne)
            'applies' => $applies,
            'covering' => $covering,
        ];
    }

    /**
     * Clients actifs (une course ces 30 derniers jours) que personne ne ramasse ce jour-là, par zone,
     * avec les courses validées qui attendent un ramasseur.
     *
     * @return list<array<string, mixed>>
     */
    private function gaps(int $companyId, string $date): array
    {
        $active = Order::query()->where('created_at', '>=', now()->subDays(30))->distinct()->pluck('merchant_id')->filter();
        $waiting = Order::query()->where('status', OrderStatus::Confirmed->value)->whereNull('pickup_hub_id')
            ->whereDoesntHave('assignments', fn ($q) => $q->active()->where('type', AssignmentType::Pickup->value))
            ->selectRaw('merchant_id, count(*) as n')->groupBy('merchant_id')->pluck('n', 'merchant_id');

        $zones = Zone::query()->pluck('name', 'id');

        return Merchant::query()->whereIn('id', $active->merge($waiting->keys())->unique())->where('status', 'active')
            ->get(['id', 'business_name', 'pickup_zone_id'])
            ->filter(fn (Merchant $m) => $this->planning->resolveFor($companyId, CourierRule::PICKUP, $date, $m->pickup_zone_id, $m->id) === null)
            ->groupBy(fn (Merchant $m) => $m->pickup_zone_id ?? 0)
            ->map(fn ($merchants, $zoneId) => [
                'zone' => $zoneId ? ['id' => (int) $zoneId, 'name' => $zones[$zoneId] ?? '—'] : null,
                'merchants' => $merchants->map(fn (Merchant $m) => [
                    'id' => $m->id, 'name' => $m->business_name, 'waiting' => (int) ($waiting[$m->id] ?? 0),
                ])->sortByDesc('waiting')->values(),
                'waiting' => $merchants->sum(fn (Merchant $m) => (int) ($waiting[$m->id] ?? 0)),
            ])
            ->sortByDesc('waiting')->values()->all();
    }
}
