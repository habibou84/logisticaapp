<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\UpdateCompanyRequest;
use App\Http\Resources\V1\CompanyResource;
use App\Models\Company;
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
        $company->update($request->validated());

        return CompanyResource::make($company->loadCount('users'));
    }
}
