<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\HubResource;
use App\Models\Hub;
use App\Rules\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class HubController extends Controller
{
    /**
     * Entrepôts de l'entreprise (visibles par tous ses utilisateurs : choix du stock d'une course).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $hubs = Hub::query()
            ->with('zone.parent')
            ->when(! $request->boolean('include_inactive') || $request->user()->merchant_id !== null, fn ($q) => $q->active())
            ->orderBy('name')
            ->get();

        return HubResource::collection($hubs);
    }

    public function store(Request $request): HubResource
    {
        $hub = Hub::create($request->validate($this->rules($request)));

        return HubResource::make($hub->load('zone.parent'));
    }

    public function update(Request $request, Hub $hub): HubResource
    {
        $hub->update($request->validate($this->rules($request, $hub)));

        return HubResource::make($hub->load('zone.parent'));
    }

    private function rules(Request $request, ?Hub $hub = null): array
    {
        $required = $hub ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:100'],
            'zone_id' => [$required, 'integer', Rule::exists('zones', 'id')->where('company_id', $request->user()->company_id)],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'landmark' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', new PhoneNumber],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
