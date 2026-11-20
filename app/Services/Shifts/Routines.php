<?php

namespace App\Services\Shifts;

use App\Enums\BillingFormula;
use App\Exceptions\BusinessRuleException;
use App\Models\BillingPlan;
use App\Models\CourierRoutine;
use App\Models\CourierRoutineStop;
use App\Models\Destination;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Parcours des livreurs en MAD, écrits par l'équipe (fiche du contrat) ou par le client (son espace).
 * Une étape est un lieu de l'annuaire (choisi, ou ajouté sur place : nom, adresse, position) ou le siège du client.
 * Enregistrer remplace toutes les étapes ; le changement vaut tout de suite (pas de validation).
 */
class Routines
{
    public const MAX_STOPS = 15;

    /**
     * @return array<string, mixed>
     */
    public static function rules(BillingPlan $plan): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['integer', 'between:1,7'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'courier_id' => ['nullable', 'integer', Rule::in($plan->couriers()->pluck('couriers.id')->all())],
            'is_active' => ['sometimes', 'boolean'],
            'stops' => ['required', 'array', 'min:1', 'max:'.self::MAX_STOPS],
            'stops.*.place_type' => ['required', Rule::in(CourierRoutineStop::PLACES)],
            'stops.*.destination_id' => ['nullable', 'integer'],
            'stops.*.new_place' => ['nullable', 'array'],
            'stops.*.new_place.name' => ['required_with:stops.*.new_place', 'string', 'max:191'],
            'stops.*.new_place.address' => ['nullable', 'string', 'max:255'],
            'stops.*.new_place.lat' => ['required_with:stops.*.new_place', 'numeric', 'between:-90,90'],
            'stops.*.new_place.lng' => ['required_with:stops.*.new_place', 'numeric', 'between:-180,180'],
            'stops.*.action' => ['required', Rule::in(CourierRoutineStop::ACTIONS)],
            'stops.*.instructions' => ['nullable', 'string', 'max:255'],
            'stops.*.expected_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'name' => 'nom', 'days' => 'jours', 'starts_at' => 'heure de départ', 'courier_id' => 'livreur', 'stops' => 'étapes',
            'stops.*.place_type' => 'lieu', 'stops.*.action' => 'action', 'stops.*.new_place.name' => 'nom du lieu',
            'stops.*.new_place.lat' => 'position du lieu', 'stops.*.new_place.lng' => 'position du lieu',
            'stops.*.instructions' => 'consigne', 'stops.*.expected_time' => 'heure de passage',
        ];
    }

    /** Seul un contrat MAD a des parcours */
    public function ensureMad(BillingPlan $plan): void
    {
        if ($plan->formula !== BillingFormula::MadContract) {
            throw new BusinessRuleException('Les parcours ne concernent que les contrats de mise à disposition (MAD).', 'billing_plan');
        }
    }

    /**
     * @param  array<string, mixed>  $data  données validées (rules)
     */
    public function save(BillingPlan $plan, ?CourierRoutine $routine, array $data, User $by, string $source): CourierRoutine
    {
        $this->ensureMad($plan);

        return DB::transaction(function () use ($plan, $routine, $data, $by, $source) {
            $routine ??= new CourierRoutine([
                'company_id' => $plan->company_id,
                'billing_plan_id' => $plan->id,
                'merchant_id' => $plan->merchant_id,
                'created_by' => $by->id,
            ]);
            $routine->fill([
                'name' => trim($data['name']),
                'days' => collect($data['days'])->map(fn ($d) => (int) $d)->unique()->sort()->values()->all(),
                'starts_at' => $data['starts_at'] ?? null,
                'courier_id' => $data['courier_id'] ?? null,
                'is_active' => $data['is_active'] ?? $routine->is_active ?? true,
                'source' => $source,
                'updated_by' => $by->id,
            ])->save();

            $routine->stops()->delete();
            foreach (array_values($data['stops']) as $i => $stop) {
                $destinationId = null;
                if ($stop['place_type'] === 'destination') {
                    $destinationId = ! empty($stop['new_place'])
                        ? $this->newPlace($plan, $stop['new_place'], $by)->id
                        : $this->existingPlace($plan, $stop['destination_id'] ?? null, $i)->id;
                }
                $routine->stops()->create([
                    'position' => $i + 1,
                    'place_type' => $stop['place_type'],
                    'destination_id' => $destinationId,
                    'action' => $stop['action'],
                    'instructions' => filled($stop['instructions'] ?? null) ? trim($stop['instructions']) : null,
                    'expected_time' => $stop['expected_time'] ?? null,
                ]);
            }

            return $routine->fresh(['stops.destination', 'courier.user', 'editor:id,name', 'merchant']);
        });
    }

    /**
     * Parcours d'un contrat, prêts pour l'écran.
     *
     * @return list<array<string, mixed>>
     */
    public function list(BillingPlan $plan): array
    {
        return CourierRoutine::withoutGlobalScopes()->where('billing_plan_id', $plan->id)
            ->with(['stops.destination', 'courier.user', 'editor:id,name', 'merchant'])->orderBy('id')->get()
            ->map(fn (CourierRoutine $r) => $this->present($r))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(CourierRoutine $routine): array
    {
        $merchant = $routine->merchant;

        return [
            'id' => $routine->id,
            'billing_plan_id' => $routine->billing_plan_id,
            'name' => $routine->name,
            'days' => array_map('intval', (array) $routine->days),
            'starts_at' => $routine->starts_at,
            'courier_id' => $routine->courier_id,
            'courier' => $routine->courier?->user?->name,
            'is_active' => $routine->is_active,
            'source' => $routine->source,
            'updated_by' => $routine->editor?->name,
            'updated_at' => $routine->updated_at?->toIso8601String(),
            'stops' => $routine->stops->map(fn (CourierRoutineStop $s) => [
                'id' => $s->id,
                'position' => $s->position,
                'place_type' => $s->place_type,
                'destination_id' => $s->destination_id,
                'action' => $s->action,
                'action_label' => CourierRoutineStop::ACTION_LABELS[$s->action] ?? $s->action,
                'instructions' => $s->instructions,
                'expected_time' => $s->expected_time,
                ...$s->place($merchant),
            ])->values()->all(),
            // Première étape sans position : le livreur ne pourra pas pointer par GPS
            'first_located' => ($first = $routine->stops->first()) !== null && $first->place($merchant)['lat'] !== null,
        ];
    }

    /**
     * Lieux de l'annuaire pour une étape (recherche par nom, sigle, quartier, adresse).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function searchPlaces(int $companyId, string $term): Collection
    {
        $normalized = Destination::normalize($term);
        if ($normalized === '') {
            return collect();
        }

        return Destination::query()->forCompany($companyId)->where('status', 'active')
            ->where('search', 'like', '%'.$normalized.'%')
            ->orderByDesc('deliveries_count')->orderBy('name')->limit(12)->get()
            ->map(fn (Destination $d) => [
                'id' => $d->id,
                'label' => $d->label(),
                'address' => trim(implode(', ', array_filter([$d->district, $d->address, $d->landmark]))) ?: null,
                'located' => $d->hasLocation(),
            ]);
    }

    private function existingPlace(BillingPlan $plan, ?int $id, int $index): Destination
    {
        $destination = $id ? Destination::query()->forCompany($plan->company_id)->find($id) : null;
        if ($destination === null) {
            throw new BusinessRuleException('Étape '.($index + 1).' : choisissez un lieu dans la liste ou ajoutez-le.', "stops.{$index}.destination_id");
        }

        return $destination;
    }

    /**
     * Lieu nouveau (agence du client…) : ajouté à l'annuaire avec sa position.
     *
     * @param  array<string, mixed>  $place
     */
    private function newPlace(BillingPlan $plan, array $place, User $by): Destination
    {
        return Destination::create([
            'company_id' => $plan->company_id,
            'name' => trim($place['name']),
            'address' => filled($place['address'] ?? null) ? trim($place['address']) : null,
            'lat' => round((float) $place['lat'], 7),
            'lng' => round((float) $place['lng'], 7),
            'location_source' => 'staff',
            'located_at' => now(),
            'located_by' => $by->id,
            'source' => 'routine',
            'status' => 'active',
            'created_by' => $by->id,
        ]);
    }
}
