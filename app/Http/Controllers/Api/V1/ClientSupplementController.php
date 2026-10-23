<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Activity;
use App\Enums\ClientInvoiceStatus;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\ClientInvoice;
use App\Models\ClientSupplement;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Suppléments des clients sous contrat : demandes exceptionnelles ajoutées à la facture du mois (section
 * « Suppléments »). L'équipe les saisit ; le client voit les siens au fil du mois. Figés à l'émission.
 */
class ClientSupplementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->merchant_id !== null || $user->canAny([Permission::InvoicesView->value, Permission::MerchantsView->value]), 403);
        $data = $request->validate([
            'merchant_id' => ['nullable', 'integer'],
            'activity' => ['nullable', Rule::in([Activity::Distribution->value, Activity::Mad->value])],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $month = isset($data['month']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['month'].'-01') : $this->today($user)->startOfMonth();
        $merchantId = $user->merchant_id ?? ($data['merchant_id'] ?? null);

        $supplements = ClientSupplement::query()
            ->with(['merchant:id,business_name', 'order:id,tracking_code', 'creator:id,name', 'invoice:id,number'])
            ->when($merchantId, fn ($q) => $q->where('merchant_id', $merchantId))
            ->when($data['activity'] ?? null, fn ($q, $a) => $q->where('activity', $a))
            ->whereDate('performed_on', '>=', $month->toDateString())
            ->whereDate('performed_on', '<', $month->addMonth()->toDateString())
            ->orderByDesc('performed_on')->orderByDesc('id')
            ->get()
            ->filter(fn (ClientSupplement $s) => $user->seesActivity($s->activity))->values();

        return response()->json([
            'data' => $supplements->map(fn (ClientSupplement $s) => $this->row($s, $user)),
            'total' => (int) $supplements->sum(fn (ClientSupplement $s) => $s->amount()),
            'month_label' => ucfirst($month->locale('fr')->translatedFormat('F Y')),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->authorizeManage($request);
        $data = $request->validate([
            'merchant_id' => ['required', 'integer', Rule::exists('merchants', 'id')->where('company_id', $user->company_id)],
            'activity' => ['required', Rule::in([Activity::Distribution->value, Activity::Mad->value])],
            ...$this->rules(),
            'order_id' => ['nullable', 'integer'],
        ], [], $this->attributes());

        $merchant = Merchant::findOrFail($data['merchant_id']);
        $activity = Activity::from($data['activity']);
        abort_unless($user->seesActivity($activity), 403, 'Vous ne gérez pas cette activité.');
        if (! $merchant->hasActivity($activity)) {
            throw new BusinessRuleException("Ce client n'a pas l'activité {$activity->label()}.", 'activity');
        }
        if (isset($data['order_id']) && ! Order::where('merchant_id', $merchant->id)->whereKey($data['order_id'])->exists()) {
            throw new BusinessRuleException('Cette course n\'est pas celle de ce client.', 'order_id');
        }
        $performedOn = $data['performed_on'] ?? $this->today($user)->toDateString();
        $this->ensureOpen($merchant->id, $activity, $performedOn);

        $supplement = ClientSupplement::create([
            ...$data,
            'performed_on' => $performedOn,
            'quantity' => $data['quantity'] ?? 1,
            'company_id' => $user->company_id,
            'created_by' => $user->id,
        ]);

        return response()->json(['data' => $this->row($supplement->load(['merchant:id,business_name', 'order:id,tracking_code', 'creator:id,name']), $user)], 201);
    }

    public function update(Request $request, ClientSupplement $clientSupplement): JsonResponse
    {
        $user = $this->authorizeManage($request);
        $this->ensureEditable($clientSupplement);
        $data = $request->validate(collect($this->rules())->map(fn ($rules) => ['sometimes', ...array_diff($rules, ['required'])])->all(), [], $this->attributes());
        if (isset($data['performed_on'])) {
            $this->ensureOpen($clientSupplement->merchant_id, $clientSupplement->activity, $data['performed_on']);
        }
        $clientSupplement->update($data);

        return response()->json(['data' => $this->row($clientSupplement->load(['merchant:id,business_name', 'order:id,tracking_code', 'creator:id,name']), $user)]);
    }

    public function destroy(Request $request, ClientSupplement $clientSupplement): JsonResponse
    {
        $this->authorizeManage($request);
        $this->ensureEditable($clientSupplement);
        $clientSupplement->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'performed_on' => ['nullable', 'date_format:Y-m-d'],
            'label' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'unit_price' => ['required', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return ['performed_on' => 'date', 'label' => 'libellé', 'quantity' => 'quantité', 'unit_price' => 'prix', 'activity' => 'activité', 'merchant_id' => 'client'];
    }

    private function authorizeManage(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->merchant_id === null && $user->canAny([Permission::InvoicesManage->value, Permission::RatesManage->value]), 403);

        return $user;
    }

    /** Le mois du supplément n'a pas encore sa facture émise */
    private function ensureOpen(int $merchantId, Activity $activity, string $date): void
    {
        $period = CarbonImmutable::parse($date)->startOfMonth()->toDateString();
        $issued = ClientInvoice::withoutGlobalScopes()->where('merchant_id', $merchantId)->where('activity', $activity->value)
            ->whereDate('period', $period)->whereIn('status', [ClientInvoiceStatus::Issued->value, ClientInvoiceStatus::Paid->value])->exists();
        if ($issued) {
            throw new BusinessRuleException('La facture de ce mois est déjà émise : datez le supplément du mois suivant.', 'performed_on');
        }
    }

    private function ensureEditable(ClientSupplement $supplement): void
    {
        if ($supplement->isLocked()) {
            throw new BusinessRuleException('Ce supplément est sur une facture émise : il ne change plus.', 'label');
        }
    }

    private function today(User $user): CarbonImmutable
    {
        return CarbonImmutable::now($user->company?->timezone ?: config('app.timezone'));
    }

    /** @return array<string, mixed> */
    private function row(ClientSupplement $s, User $user): array
    {
        return [
            'id' => $s->id,
            'merchant' => $s->merchant ? ['id' => $s->merchant->id, 'business_name' => $s->merchant->business_name] : null,
            'activity' => $s->activity->value,
            'activity_label' => $s->activity->label(),
            'performed_on' => $s->performed_on->toDateString(),
            'label' => $s->label,
            'quantity' => $s->quantity,
            'unit_price' => $s->unit_price,
            'amount' => $s->amount(),
            'order' => $s->order ? ['id' => $s->order->id, 'tracking_code' => $s->order->tracking_code] : null,
            'invoice_number' => $s->relationLoaded('invoice') ? $s->invoice?->number : null,
            'locked' => $s->isLocked(),
            'created_by' => $user->merchant_id === null ? $s->creator?->name : null,
        ];
    }
}
