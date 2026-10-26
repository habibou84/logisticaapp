<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\UpdateCompanyRequest;
use App\Http\Resources\V1\CompanyResource;
use App\Models\Company;
use App\Policies\CompanyPolicy;
use App\Services\Departments\DepartmentSettings;
use App\Services\Destinations\DestinationSettings;
use App\Services\FieldEvents\FieldEventSettings;
use App\Services\Finance\Fne;
use App\Services\Finance\Vat;
use App\Services\Orders\ActivityOptions;
use App\Services\Ratings\RatingSettings;
use App\Services\Vehicles\VehicleSettings;
use App\Support\InvoicePayments;
use Illuminate\Support\Facades\Gate;

class CompanyController extends Controller
{
    public function show(Company $company): CompanyResource
    {
        Gate::authorize('view', $company);

        return CompanyResource::make($company->loadCount('users'));
    }

    public function update(UpdateCompanyRequest $request, Company $company): CompanyResource
    {
        $data = $request->validated();
        if (array_key_exists('invoice_payment_methods', $data)) {
            $data['invoice_payment_methods'] = InvoicePayments::clean($data['invoice_payment_methods']);
        }
        if (array_key_exists('activity_options', $data)) {
            $data['activity_options'] = collect($data['activity_options'] ?? [])
                ->map(fn ($options) => ActivityOptions::clean((array) $options))->all();
        }
        if (array_key_exists('rating_settings', $data)) {
            $data['rating_settings'] = RatingSettings::clean((array) ($data['rating_settings'] ?? []));
        }
        if (array_key_exists('field_event_settings', $data)) {
            $data['field_event_settings'] = FieldEventSettings::clean((array) ($data['field_event_settings'] ?? []));
        }
        if (array_key_exists('destination_settings', $data)) {
            $data['destination_settings'] = DestinationSettings::clean((array) ($data['destination_settings'] ?? []));
        }
        if (array_key_exists('department_settings', $data)) {
            $data['department_settings'] = DepartmentSettings::clean((array) ($data['department_settings'] ?? []));
        }
        if (array_key_exists('fne_settings', $data)) {
            $data['fne_settings'] = Fne::clean((array) ($data['fne_settings'] ?? []));
        }
        // Clé API FNE : remplacée seulement quand une nouvelle est saisie (jamais renvoyée)
        if (array_key_exists('fne_api_key', $data) && blank($data['fne_api_key'])) {
            unset($data['fne_api_key']);
        }
        if (array_key_exists('vat_settings', $data)) {
            $data['vat_settings'] = Vat::clean((array) ($data['vat_settings'] ?? []));
        }
        if (array_key_exists('vehicle_settings', $data)) {
            $data['vehicle_settings'] = VehicleSettings::clean((array) ($data['vehicle_settings'] ?? []));
        }
        // Seuls les réglages qui changent comptent : chacun demande la permission de son domaine
        $company->fill($data);
        foreach (array_keys($company->getDirty()) as $field) {
            abort_unless($request->user()->can(CompanyPolicy::permissionFor($field)->value), 403,
                'Vous n\'avez pas le droit de modifier ce réglage ('.CompanyPolicy::permissionFor($field)->label().').');
        }
        $company->save();

        return CompanyResource::make($company->loadCount('users'));
    }
}
