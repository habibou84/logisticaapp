<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Activity;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\IncidentReasonResource;
use App\Models\IncidentReason;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Réglages › Motifs : motifs de non-livraison et de ramassage, notes de suivi de l'équipe. Les motifs communs se
 * règlent (libellé, effets, activités, ordre) ou se désactivent ; l'administrateur en ajoute pour son entreprise.
 */
class IncidentReasonController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return IncidentReasonResource::collection($this->visible($request)->orderBy('sort_order')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $reason = IncidentReason::create([
            ...$data,
            'company_id' => $request->user()->company_id,
            'code' => 'c_'.Str::lower(Str::random(10)),
            'sort_order' => $data['sort_order'] ?? ((int) $this->visible($request)->max('sort_order') + 1),
        ]);

        return IncidentReasonResource::make($reason)->response()->setStatusCode(201);
    }

    public function update(Request $request, IncidentReason $incidentReason): IncidentReasonResource
    {
        abort_unless($incidentReason->company_id === null || $incidentReason->company_id === $request->user()->company_id, 404);
        $incidentReason->update($this->validated($request, true));

        return IncidentReasonResource::make($incidentReason->fresh());
    }

    /** Motif propre à l'entreprise, jamais utilisé ; les autres se désactivent */
    public function destroy(Request $request, IncidentReason $incidentReason): JsonResponse
    {
        abort_unless($incidentReason->company_id === $request->user()->company_id, 403, 'Un motif commun se désactive, il ne se supprime pas.');
        $used = Order::withoutGlobalScopes()->where('last_incident_reason_id', $incidentReason->id)
            ->orWhere('follow_up_reason_id', $incidentReason->id)->exists();
        abort_if($used, 422, 'Ce motif a déjà servi : désactivez-le plutôt.');
        $incidentReason->delete();

        return response()->json(null, 204);
    }

    private function visible(Request $request)
    {
        return IncidentReason::query()->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $request->user()->company_id));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'label' => [$req, 'string', 'max:120'],
            'applies_to' => [$req, Rule::in(IncidentReason::STAGES)],
            'family' => ['sometimes', 'nullable', Rule::in(array_keys(IncidentReason::FAMILIES))],
            'activities' => ['sometimes', 'nullable', 'array'],
            'activities.*' => [Rule::enum(Activity::class)],
            'requires_date' => ['sometimes', 'boolean'],
            'requires_note' => ['sometimes', 'boolean'],
            'photo' => ['sometimes', Rule::in(IncidentReason::PHOTO)],
            'counts_as_attempt' => ['sometimes', 'boolean'],
            'triggers_return' => ['sometimes', 'boolean'],
            'destination_effect' => ['sometimes', 'nullable', Rule::in(IncidentReason::DESTINATION_EFFECTS)],
            'holds' => ['sometimes', 'boolean'],
            'visible_to_client' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ], [], ['label' => 'libellé', 'applies_to' => 'étape']);
    }
}
