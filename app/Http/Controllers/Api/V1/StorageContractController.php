<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Enums\StorageBillingType;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\StorageContractResource;
use App\Models\StorageContract;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Contrats de stockage : le marchand consulte les siens, l'administration les gère.
 */
class StorageContractController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        if ($user->merchant_id === null && ! $user->canAny([Permission::StockManage->value, Permission::FinanceView->value, Permission::SettingsManage->value])) {
            throw new AuthorizationException;
        }

        $contracts = StorageContract::query()
            ->with(['merchant', 'hub', 'charges' => fn ($q) => $q->latest('period')->limit(6)])
            ->when($user->merchant_id, fn ($q) => $q->where('merchant_id', $user->merchant_id))
            ->when(! $user->merchant_id && $request->filled('merchant_id'), fn ($q) => $q->where('merchant_id', $request->integer('merchant_id')))
            ->orderByDesc('starts_on')
            ->get();

        return StorageContractResource::collection($contracts);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $contract = StorageContract::create($request->validate($this->rules($request)));

        return StorageContractResource::make($contract->load(['merchant', 'hub', 'charges']))->response()->setStatusCode(201);
    }

    public function update(Request $request, StorageContract $storageContract): StorageContractResource
    {
        $this->authorizeManage($request);

        $data = $request->validate($this->rules($request, $storageContract));

        // Contrat déjà facturé : seules la date de fin et la note changent (l'historique reste juste)
        if ($storageContract->charges()->exists() && array_diff(array_keys($data), ['ends_on', 'notes']) !== []) {
            throw new BusinessRuleException('Ce contrat a déjà été facturé : terminez-le et créez-en un nouveau pour changer le tarif.', 'status');
        }

        $storageContract->update($data);

        return StorageContractResource::make($storageContract->load(['merchant', 'hub', 'charges']));
    }

    public function destroy(Request $request, StorageContract $storageContract): JsonResponse
    {
        $this->authorizeManage($request);

        if ($storageContract->charges()->exists()) {
            throw new BusinessRuleException('Ce contrat a déjà été facturé : indiquez plutôt une date de fin.', 'status');
        }

        $storageContract->delete();

        return response()->json(null, 204);
    }

    private function authorizeManage(Request $request): void
    {
        $user = $request->user();

        if ($user->merchant_id !== null || ! $user->canAny([Permission::SettingsManage->value, Permission::FinanceManage->value])) {
            throw new AuthorizationException;
        }
    }

    private function rules(Request $request, ?StorageContract $contract = null): array
    {
        $companyId = $request->user()->company_id;
        $required = $contract ? 'sometimes' : 'required';

        return [
            'merchant_id' => [$required, 'integer', Rule::exists('merchants', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'hub_id' => ['sometimes', 'nullable', 'integer', Rule::exists('hubs', 'id')->where('company_id', $companyId)],
            'billing_type' => [$required, Rule::enum(StorageBillingType::class)],
            'price' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'starts_on' => [$required, 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:'.($request->input('starts_on') ?? $contract?->starts_on?->toDateString() ?? 'today')],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
