<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\RescheduleRequest;
use App\Services\Orders\Reschedules;
use App\Support\Branding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Page de réponse de le client (lien du message WhatsApp, valable 3 jours, sans connexion) : nouvelle date ou
 * annulation pour chaque colis pas ramassé. N'expose que le code, le prénom du client, la zone et le montant.
 */
class PublicRescheduleController extends Controller
{
    public function __construct(private readonly Reschedules $reschedules) {}

    public function show(string $token): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($token))]);
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $rescheduleRequest = $this->find($token);
        abort_unless($rescheduleRequest->isOpen(), 410, 'Ce lien n\'est plus valable. Contactez l\'agence.');

        $data = $request->validate([
            'choices' => ['required', 'array', 'min:1', 'max:100'],
            'choices.*.order_id' => ['required', 'integer', 'distinct'],
            'choices.*.date' => ['nullable', 'date_format:Y-m-d', 'required_without:choices.*.cancel'],
            'choices.*.cancel' => ['nullable', 'string', Rule::in(array_keys(Reschedules::CANCEL_REASONS))],
            'choices.*.note' => ['nullable', 'string', 'max:300'],
        ]);

        $plan = collect($data['choices'])->mapWithKeys(fn ($c) => [(int) $c['order_id'] => array_filter([
            'date' => isset($c['cancel']) ? null : ($c['date'] ?? null),
            'cancel' => $c['cancel'] ?? null,
            'note' => $c['note'] ?? null,
        ], fn ($v) => $v !== null)])->all();

        $result = $this->reschedules->answer($rescheduleRequest, $plan, 'page');

        return response()->json(['data' => [...$this->present($rescheduleRequest->fresh()), 'result' => $result]]);
    }

    private function find(string $token): RescheduleRequest
    {
        $request = RescheduleRequest::withoutGlobalScopes()->where('token_hash', RescheduleRequest::hashToken($token))->first();
        abort_if($request === null, 404);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(RescheduleRequest $request): array
    {
        $company = Company::find($request->company_id);
        $merchant = Merchant::withoutGlobalScopes()->find($request->merchant_id);
        $open = $request->isOpen();

        return [
            'company' => Branding::data($company),
            'merchant_name' => $merchant?->business_name,
            'missed_day' => $request->missed_day->toDateString(),
            'note' => $request->note,
            'open' => $open,
            'expired' => $request->status === RescheduleRequest::PENDING && ! $open,
            'today' => Reschedules::today($company),
            'max_date' => Reschedules::maxDate($company),
            'orders' => $open ? $this->reschedules->pendingOrders($request)->map(fn (Order $o, int $n) => [
                'number' => $n,
                'id' => $o->id,
                'tracking_code' => $o->tracking_code,
                'recipient_name' => strtok((string) $o->recipient_name, ' ') ?: null,
                'zone_name' => $o->deliveryZone?->name,
                'cod_amount' => (int) $o->cod_amount,
                'delivery_day' => ($o->delivery_scheduled_date ?? $o->created_at)->toDateString(),
            ])->values() : [],
            'reasons' => collect(Reschedules::CANCEL_REASONS)->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values(),
        ];
    }
}
