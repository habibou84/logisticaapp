<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\UpdateCompanyRequest;
use App\Http\Resources\V1\CompanyResource;
use App\Models\Company;
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
        $company->update($data);

        return CompanyResource::make($company->loadCount('users'));
    }
}
