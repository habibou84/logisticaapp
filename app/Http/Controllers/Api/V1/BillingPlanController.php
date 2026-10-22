<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BillingFormula;
use App\Enums\DeliveryDelay;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BillingPlanResource;
use App\Models\BillingPlan;
use App\Models\Merchant;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Formules de facturation d'un client (distribution, MAD). Une seule en cours à la fois : en créer une
 * nouvelle termine la précédente la veille de son début.
 */
class BillingPlanController extends Controller
{
    public function index(Request $request, Merchant $merchant): AnonymousResourceCollection
    {
        abort_unless($request->user()->can(Permission::MerchantsView->value), 403);

        return BillingPlanResource::collection($merchant->billingPlans()->with('couriers.user')->get());
    }

    public function store(Request $request, Merchant $merchant): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $this->validated($request);

        $plan = DB::transaction(function () use ($merchant, $data) {
            $starts = CarbonImmutable::parse($data['starts_on']);
            // La formule en cours s'arrête la veille ; une formule qui n'a pas commencé est remplacée
            BillingPlan::where('merchant_id', $merchant->id)->whereDate('starts_on', '>=', $starts)->get()
                ->each(fn (BillingPlan $p) => Order::where('billing_plan_id', $p->id)->exists()
                    ? throw new BusinessRuleException('Une formule qui commence à cette date ou après a déjà des courses.', 'starts_on')
                    : $p->delete());
            BillingPlan::where('merchant_id', $merchant->id)->activeOn($starts)
                ->update(['ends_on' => $starts->subDay()->toDateString()]);

            $plan = BillingPlan::create([
                'monthly_fee' => 0, 'included_quantity' => 0, 'overage_price' => 0, 'cheque_price' => 0, 'extra_kg_price' => 0,
                ...collect($data)->except('courier_ids')->all(),
                'merchant_id' => $merchant->id,
                'company_id' => $merchant->company_id,
            ]);
            $plan->couriers()->sync($plan->formula->isMad() ? ($data['courier_ids'] ?? []) : []);

            return $plan;
        });

        return BillingPlanResource::make($plan->load('couriers.user'))->response()->setStatusCode(201);
    }

    public function update(Request $request, BillingPlan $billingPlan): BillingPlanResource
    {
        $this->authorizeManage($request);
        $data = $this->validated($request, $billingPlan);

        // Formule déjà utilisée : ses prix restent ceux des courses passées (nouvelle formule pour les changer)
        $priced = ['formula', 'monthly_fee', 'included_quantity', 'overage_price', 'delivery_delay', 'cheque_price', 'extra_kg_price', 'starts_on'];
        $changed = collect($priced)->filter(fn ($f) => array_key_exists($f, $data)
            && (string) ($data[$f] ?? '') !== (string) ($billingPlan->getAttributes()[$f] ?? ''))->values();
        if ($changed->isNotEmpty() && Order::where('billing_plan_id', $billingPlan->id)->exists()) {
            throw new BusinessRuleException('Cette formule a déjà des courses : indiquez une date de fin et créez-en une nouvelle pour changer ses conditions.', $changed->first());
        }

        DB::transaction(function () use ($billingPlan, $data) {
            $billingPlan->update(collect($data)->except('courier_ids')->all());
            if (array_key_exists('courier_ids', $data) || ! $billingPlan->formula->isMad()) {
                $billingPlan->couriers()->sync($billingPlan->formula->isMad() ? ($data['courier_ids'] ?? []) : []);
            }
        });

        return BillingPlanResource::make($billingPlan->fresh('couriers.user'));
    }

    public function destroy(Request $request, BillingPlan $billingPlan): JsonResponse
    {
        $this->authorizeManage($request);

        if (Order::where('billing_plan_id', $billingPlan->id)->exists()) {
            throw new BusinessRuleException('Cette formule a déjà des courses : indiquez plutôt une date de fin.', 'ends_on');
        }
        $billingPlan->delete();

        return response()->json(null, 204);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can(Permission::MerchantsManage->value), 403);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?BillingPlan $plan = null): array
    {
        $sometimes = $plan ? 'sometimes' : 'required';
        $formula = BillingFormula::tryFrom((string) $request->input('formula', $plan?->formula->value));
        $forfait = $formula?->hasForfait() ?? false;

        $data = $request->validate([
            'formula' => [$sometimes, Rule::enum(BillingFormula::class)],
            'monthly_fee' => [$forfait && ! $plan ? 'required' : 'sometimes', 'integer', 'min:0'],
            'included_quantity' => [$forfait && ! $plan ? 'required' : 'sometimes', 'integer', 'min:0'],
            'overage_price' => ['sometimes', 'integer', 'min:0'],
            'delivery_delay' => [$sometimes, Rule::enum(DeliveryDelay::class)],
            'cheque_price' => ['sometimes', 'integer', 'min:0'],
            'extra_kg_price' => ['sometimes', 'integer', 'min:0'],
            'starts_on' => [$sometimes, 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.($request->input('starts_on') ?? $plan?->starts_on?->toDateString() ?? '1970-01-01')],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'courier_ids' => ['sometimes', 'array', 'max:20'],
            'courier_ids.*' => ['integer', Rule::exists('couriers', 'id')->where('company_id', $request->user()->company_id)],
        ], [], [
            'formula' => 'formule',
            'monthly_fee' => 'forfait mensuel',
            'included_quantity' => 'quantité incluse',
            'overage_price' => 'prix au-delà du forfait',
            'delivery_delay' => 'délai',
            'cheque_price' => 'prix du chèque',
            'extra_kg_price' => 'prix du kilogramme en plus',
            'starts_on' => 'date de début',
            'ends_on' => 'date de fin',
            'courier_ids' => 'livreurs mis à disposition',
        ]);

        // Sans forfait : rien d'inclus
        if ($formula !== null && ! $forfait) {
            $data = [...$data, 'monthly_fee' => 0, 'included_quantity' => 0, 'overage_price' => 0];
        }

        return $data;
    }
}
