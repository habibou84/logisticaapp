<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\UpdateCompanyRequest;
use App\Http\Resources\V1\CompanyResource;
use App\Models\Company;
use App\Services\FieldEvents\FieldEventSettings;
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
        if (array_key_exists('vehicle_settings', $data)) {
            $data['vehicle_settings'] = VehicleSettings::clean((array) ($data['vehicle_settings'] ?? []));
        }
        $company->update($data);

        return CompanyResource::make($company->loadCount('users'));
    }
}
