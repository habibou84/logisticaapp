<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DepartmentResource;
use App\Models\Department;
use App\Models\Merchant;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Services\Departments\Departments;
use App\Services\Departments\DepartmentSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Départements d'un client (fiche du client › Départements, ou application du client) : liste, quotas du
 * mois, réglages du client, rattachement des comptes.
 */
class DepartmentController extends Controller
{
    public function __construct(private readonly Departments $departments, private readonly DepartmentSettings $settings) {}

    /** Départements du client connecté (application client) */
    public function mine(Request $request): JsonResponse
    {
        $merchant = Merchant::findOrFail($request->user()->merchant_id ?? abort(404));

        return $this->index($request, $merchant);
    }

    public function index(Request $request, Merchant $merchant): JsonResponse
    {
        Gate::authorize('view', $merchant);
        $user = $request->user();
        $manage = $user->can('manageDepartments', $merchant);
        $staff = $user->merchant_id === null;

        $departments = Department::query()->where('merchant_id', $merchant->id)
            ->when(! $manage, fn ($q) => $q->active())
            ->with(['pickupZone:id,name', 'users:id'])->withCount('orders')->ordered()->get()
            ->each(fn (Department $d) => $d->setAttribute('usage', $this->departments->usage($d)));

        return response()->json([
            'data' => DepartmentResource::collection($departments)->resolve($request),
            'settings' => $this->settings->for($merchant),
            // Réglages propres au client (vides : comme l'entreprise) et ceux de l'entreprise
            'overrides' => $staff ? DepartmentSettings::clean((array) ($merchant->department_settings ?? []), DepartmentSettings::CLIENT_KEYS) : null,
            'defaults' => $staff ? $this->settings->company($merchant->company) : null,
            'enabled' => $this->settings->company($merchant->company)['enabled'],
            'can_manage' => $manage,
            // Départements pour lesquels ce compte commande (null : tous)
            'mine' => $this->departments->scopeFor($user),
            'users' => $manage ? User::query()->where('merchant_id', $merchant->id)->with(['roles', 'departments:id'])->orderBy('name')->get()
                ->map(fn (User $u) => [
                    'id' => $u->id, 'name' => $u->name, 'phone' => $u->phone,
                    'owner' => $u->hasRole(Role::MerchantOwner->value),
                    'department_ids' => $u->departments->modelKeys(),
                ]) : [],
        ]);
    }

    public function store(Request $request, Merchant $merchant): JsonResponse
    {
        Gate::authorize('manageDepartments', $merchant);
        $data = $this->validated($request, $merchant);

        $department = DB::transaction(function () use ($merchant, $data) {
            $department = Department::create([
                ...$data,
                'company_id' => $merchant->company_id,
                'merchant_id' => $merchant->id,
                'position' => $data['position'] ?? (int) Department::query()->where('merchant_id', $merchant->id)->max('position') + 1,
                // Premier département : principal
                'is_main' => ($data['is_main'] ?? false) || ! Department::query()->where('merchant_id', $merchant->id)->where('is_main', true)->exists(),
            ]);
            $this->keepOneMain($department);

            return $department;
        });

        return DepartmentResource::make($department->load('pickupZone:id,name'))->response()->setStatusCode(201);
    }

    public function update(Request $request, Department $department): DepartmentResource
    {
        $merchant = $department->merchant;
        Gate::authorize('manageDepartments', $merchant);
        $data = $this->validated($request, $merchant, true);

        DB::transaction(function () use ($department, $data) {
            $department->update($data);
            $this->keepOneMain($department);
        });
        $this->departments->forget();

        return DepartmentResource::make($department->fresh()->load('pickupZone:id,name'));
    }

    /** Département sans course : supprimé ; sinon désactivé (gardé sur les anciennes courses et factures) */
    public function destroy(Department $department): JsonResponse
    {
        Gate::authorize('manageDepartments', $department->merchant);
        if ($department->orders()->exists()) {
            $department->update(['active' => false]);

            return response()->json(['data' => ['archived' => true]]);
        }
        $department->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** Réglages des départements de ce client (vide : comme l'entreprise) */
    public function updateSettings(Request $request, Merchant $merchant): JsonResponse
    {
        Gate::authorize('update', $merchant);
        $data = $request->validate(DepartmentSettings::rules());
        $merchant->forceFill(['department_settings' => DepartmentSettings::clean((array) ($data['department_settings'] ?? []), DepartmentSettings::CLIENT_KEYS)])->save();
        $this->departments->forget();

        return response()->json(['data' => $this->settings->for($merchant->fresh())]);
    }

    /** Départements d'un compte du client (gérant : aucun, il voit tout) */
    public function updateUser(Request $request, Merchant $merchant, User $user): JsonResponse
    {
        Gate::authorize('manageDepartments', $merchant);
        abort_unless($user->merchant_id === $merchant->id, 404);
        $data = $request->validate([
            'department_ids' => ['present', 'array'],
            'department_ids.*' => ['integer', Rule::exists('departments', 'id')->where('merchant_id', $merchant->id)],
        ]);
        $user->departments()->sync($data['department_ids']);
        $this->departments->forget();

        return response()->json(['data' => ['id' => $user->id, 'department_ids' => $user->departments()->pluck('departments.id')->all()]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Merchant $merchant, bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes' : 'nullable';
        $data = $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'code' => [$sometimes, 'nullable', 'string', 'max:12'],
            'manager_name' => [$sometimes, 'nullable', 'string', 'max:120'],
            'phone' => [$sometimes, 'nullable', 'string', new PhoneNumber],
            'email' => [$sometimes, 'nullable', 'email', 'max:190'],
            'location' => [$sometimes, 'nullable', 'string', 'max:255'],
            'pickup_zone_id' => [$sometimes, 'nullable', 'integer', Rule::exists('zones', 'id')->where('company_id', $merchant->company_id)],
            'pickup_address' => [$sometimes, 'nullable', 'string', 'max:500'],
            'pickup_landmark' => [$sometimes, 'nullable', 'string', 'max:255'],
            'pickup_lat' => [$sometimes, 'nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => [$sometimes, 'nullable', 'numeric', 'between:-180,180'],
            'cost_center' => [$sometimes, 'nullable', 'string', 'max:60'],
            'monthly_quota' => [$sometimes, 'nullable', 'integer', 'min:1', 'max:1000000'],
            'is_main' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'notes' => [$sometimes, 'nullable', 'string', 'max:1000'],
        ]);
        if (isset($data['code'])) {
            $data['code'] = mb_strtoupper(trim($data['code'])) ?: null;
        }
        // Sans zone : à l'adresse du client
        if (array_key_exists('pickup_zone_id', $data) && $data['pickup_zone_id'] === null) {
            $data = [...$data, 'pickup_address' => null, 'pickup_landmark' => null, 'pickup_lat' => null, 'pickup_lng' => null];
        }
        // Les notes sont internes à l'agence
        if ($request->user()->merchant_id !== null) {
            unset($data['notes']);
        }

        return $data;
    }

    private function keepOneMain(Department $department): void
    {
        if (! $department->is_main) {
            if (! Department::query()->where('merchant_id', $department->merchant_id)->where('is_main', true)->exists()) {
                throw new BusinessRuleException('Il faut un département principal : choisissez-en un autre d\'abord.', 'is_main');
            }

            return;
        }
        Department::query()->where('merchant_id', $department->merchant_id)->whereKeyNot($department->id)->where('is_main', true)->update(['is_main' => false]);
    }
}
