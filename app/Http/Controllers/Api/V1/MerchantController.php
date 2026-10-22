<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Activity;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreMerchantRequest;
use App\Http\Requests\V1\UpdateMerchantRequest;
use App\Http\Resources\V1\MerchantResource;
use App\Http\Resources\V1\UserResource;
use App\Models\BillingPlan;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Support\PhoneNumber as Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class MerchantController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Merchant::class);

        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string'],
            'source' => ['nullable', 'string', 'in:backoffice,signup'],
            'activity' => ['nullable', Rule::enum(Activity::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $merchants = Merchant::query()
            ->with(['pickupZone', 'activityRows', 'billingPlans' => fn ($q) => $q->activeOn(now())])
            ->withCount('orders')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('activity'), fn ($q) => $q->withActivity([$request->string('activity')->toString()]))
            // Inscrits en ligne
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source'))->latest())
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.mb_strtolower($request->string('search')).'%';
                $q->where(fn ($q) => $q->whereRaw('LOWER(business_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(contact_name) LIKE ?', [$term])
                    ->orWhere('phone', 'like', $term));
            })
            ->orderBy('business_name')
            ->paginate($request->integer('per_page', 50));

        return MerchantResource::collection($merchants);
    }

    public function store(StoreMerchantRequest $request): JsonResponse
    {
        $data = $request->validated();

        $merchant = DB::transaction(function () use ($data, $request) {
            $merchant = Merchant::create(Arr::except($data, ['owner', 'activities']));
            $merchant->syncActivities($data['activities'] ?? [Activity::Ecommerce]);
            self::trackLocationSource($merchant);

            if (! empty($data['owner'])) {
                $owner = User::create([
                    'company_id' => $request->user()->company_id,
                    'merchant_id' => $merchant->id,
                    'name' => $data['owner']['name'],
                    'phone' => $data['owner']['phone'],
                    'email' => $data['owner']['email'] ?? null,
                    'password' => $data['owner']['password'],
                ]);
                $owner->assignRole(Role::MerchantOwner->value);
            }

            return $merchant;
        });

        return MerchantResource::make($merchant->load(['pickupZone', 'users.roles', 'billingPlans' => fn ($q) => $q->activeOn(now())]))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Merchant $merchant): MerchantResource
    {
        Gate::authorize('view', $merchant);

        return MerchantResource::make($merchant->load(['pickupZone', 'users.roles', 'billingPlans' => fn ($q) => $q->activeOn(now())])->loadCount('orders'));
    }

    public function update(UpdateMerchantRequest $request, Merchant $merchant): MerchantResource
    {
        $data = $request->validated();
        $merchant->update(Arr::except($data, 'activities'));
        if (isset($data['activities'])) {
            // Activité retirée : seulement sans course ni formule (l'historique reste rattaché)
            $kept = array_map(fn ($a) => $a instanceof Activity ? $a->value : $a, $data['activities']);
            foreach ($merchant->activityList() as $activity) {
                if ($request->user()->seesActivity($activity) && ! in_array($activity->value, $kept, true)
                    && (Order::withoutGlobalScopes()->where('merchant_id', $merchant->id)->where('activity', $activity->value)->exists()
                        || BillingPlan::withoutGlobalScopes()->where('merchant_id', $merchant->id)->where('activity', $activity->value)->exists())) {
                    throw new BusinessRuleException("Ce client a déjà des courses ou une formule {$activity->label()} : l'activité ne peut pas être retirée.", 'activities');
                }
            }
            // Activités hors du périmètre de l'utilisateur : gardées telles quelles
            $merchant->syncActivities([
                ...array_filter($merchant->activityList(), fn (Activity $a) => ! $request->user()->seesActivity($a)),
                ...$data['activities'],
            ]);
        }
        self::trackLocationSource($merchant);

        // Un marchand suspendu ne peut plus se connecter
        if ($merchant->wasChanged('status') && ! $merchant->isActive()) {
            foreach ($merchant->users as $user) {
                $user->tokens()->delete();
            }
        }

        return MerchantResource::make($merchant->load(['pickupZone', 'users.roles', 'billingPlans' => fn ($q) => $q->activeOn(now())]));
    }

    /**
     * Position saisie ou retirée par l'agence (fiche ou API) : source et date.
     */
    private static function trackLocationSource(Merchant $merchant): void
    {
        if (! $merchant->wasChanged(['pickup_lat', 'pickup_lng']) && ! $merchant->wasRecentlyCreated) {
            return;
        }

        $merchant->forceFill($merchant->hasPickupLocation()
            ? ['pickup_location_source' => Merchant::LOCATION_STAFF, 'pickup_located_at' => now(), 'pickup_location_accuracy' => null]
            : ['pickup_location_source' => null, 'pickup_located_at' => null, 'pickup_location_accuracy' => null])->save();
    }

    /**
     * Ajoute un compte de connexion au marchand (gérant ou employé).
     */
    public function storeUser(Request $request, Merchant $merchant): JsonResponse
    {
        Gate::authorize('update', $merchant);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', new PhoneNumber],
            'email' => ['nullable', 'email', 'max:255', User::uniqueIn($merchant->company_id, 'email')],
            'password' => ['required', 'string', Password::min(8)],
            'role' => ['required', Rule::in([Role::MerchantOwner->value, Role::MerchantStaff->value])],
        ]);

        $phone = Phone::normalize($data['phone']);
        if (User::takenIn($merchant->company_id, 'phone', $phone)) {
            return response()->json([
                'message' => 'Ce téléphone est déjà utilisé.',
                'errors' => ['phone' => ['Ce téléphone est déjà utilisé.']],
            ], 422);
        }

        $user = DB::transaction(function () use ($data, $merchant) {
            $user = User::create([
                ...Arr::except($data, 'role'),
                'company_id' => $merchant->company_id,
                'merchant_id' => $merchant->id,
            ]);
            $user->assignRole($data['role']);

            return $user;
        });

        return UserResource::make($user->load('roles'))->response()->setStatusCode(201);
    }
}
