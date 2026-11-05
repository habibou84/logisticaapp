<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AssignmentType;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\RescheduleRequest;
use App\Services\Orders\Reschedules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * File « À reporter » du dispatch : colis pas ramassés dont la date de livraison est passée, regroupés par
 * client, avec l'état de la demande de nouvelle date ; demander, relancer, choisir la date.
 */
class RescheduleController extends Controller
{
    public function __construct(private readonly Reschedules $reschedules) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);
        $company = $request->user()->company;
        $today = Reschedules::today($company);

        $orders = Order::query()->toReschedule($today)
            ->with(['deliveryZone:id,name', 'rescheduleRequest',
                'assignments' => fn ($q) => $q->active()->where('type', AssignmentType::Pickup->value)->with('courier.user:id,name')])
            ->orderByRaw(Order::DELIVERY_DAY_SQL)->orderBy('id')
            ->get();

        $merchants = Merchant::query()->whereIn('id', $orders->pluck('merchant_id')->unique())
            ->get(['id', 'business_name', 'phone', 'whatsapp_phone', 'pickup_zone_id', 'pickup_address'])->load('pickupZone:id,name')->keyBy('id');
        $apps = $merchants->keys()->filter(fn ($id) => $this->reschedules->usesApp($merchants[$id]))->flip();

        $groups = $orders->groupBy('merchant_id')->map(function (Collection $list, $merchantId) use ($merchants, $apps) {
            $merchant = $merchants[$merchantId] ?? null;
            $open = $list->pluck('rescheduleRequest')->filter(fn (?RescheduleRequest $r) => $r?->isOpen())->sortByDesc('id')->first();

            return [
                'merchant' => $merchant ? [
                    'id' => $merchant->id,
                    'business_name' => $merchant->business_name,
                    'zone_name' => $merchant->pickupZone?->name,
                    'address' => $merchant->pickup_address,
                    'phone' => $merchant->messagingPhone(),
                    'uses_app' => isset($apps[$merchant->id]),
                ] : null,
                'count' => $list->count(),
                'cod_total' => (int) $list->sum('cod_amount'),
                'missed_day' => $list->map(fn (Order $o) => self::day($o))->min(),
                'request' => $open ? [
                    'id' => $open->id,
                    'origin' => $open->origin,
                    'created_at' => $open->created_at,
                    'whatsapp_sent_at' => $open->whatsapp_sent_at,
                    'reminded_at' => $open->reminded_at,
                    'expires_at' => $open->expires_at,
                    'note' => $open->note,
                    'link' => Reschedules::link($open),
                ] : null,
                'orders' => $list->map(fn (Order $o) => [
                    'id' => $o->id,
                    'tracking_code' => $o->tracking_code,
                    'recipient_name' => $o->recipient_name,
                    'delivery_zone_name' => $o->deliveryZone?->name,
                    'cod_amount' => (int) $o->cod_amount,
                    'delivery_day' => self::day($o),
                    'status' => $o->status->value,
                    'courier_name' => $o->assignments->first()?->courier?->user?->name,
                    'requested' => $o->rescheduleRequest?->isOpen() ?? false,
                ])->values(),
            ];
        })->sortBy('missed_day')->values();

        // Réponses du jour (demandes réglées par le client aujourd'hui)
        $answered = RescheduleRequest::query()->where('status', RescheduleRequest::ANSWERED)
            ->whereNotIn('answered_via', ['dispatch', 'auto'])->where('answered_at', '>=', now($company->timezone ?: config('app.timezone'))->startOfDay())
            ->with('merchant:id,business_name')->latest('answered_at')->get();

        return response()->json(['data' => [
            'today' => $today,
            'max_date' => Reschedules::maxDate($company),
            'groups' => $groups,
            'summary' => [
                'orders' => $orders->count(),
                'merchants' => $groups->count(),
                'not_asked' => $groups->whereNull('request')->count(),
                'waiting' => $groups->whereNotNull('request')->count(),
                'answered_today' => $answered->count(),
            ],
            'answered' => $answered->take(10)->map(fn (RescheduleRequest $r) => [
                'merchant_name' => $r->merchant?->business_name,
                'answered_at' => $r->answered_at,
                'via' => Reschedules::VIA[$r->answered_via] ?? $r->answered_via,
                'count' => count($r->order_ids),
            ])->values(),
            'settings' => [
                'evening' => (bool) $company->reschedule_evening,
                'evening_time' => $company->reschedule_evening_time,
                'channel' => $company->reschedule_channel,
                'no_answer' => $company->reschedule_no_answer,
            ],
            'reasons' => collect(Reschedules::CANCEL_REASONS)->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values(),
        ]]);
    }

    /** « Demander » : une demande par client coché, avec ses colis à reporter */
    public function ask(Request $request): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);
        $data = $request->validate([
            'merchant_ids' => ['required', 'array', 'min:1', 'max:100'],
            'merchant_ids.*' => ['integer', 'distinct'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $company = $request->user()->company;
        $today = Reschedules::today($company);

        $sent = 0;
        foreach (Merchant::query()->whereIn('id', $data['merchant_ids'])->get() as $merchant) {
            $orders = Order::query()->toReschedule($today)->where('merchant_id', $merchant->id)->get();
            if ($orders->isEmpty()) {
                continue;
            }
            $this->reschedules->request($company, $merchant, $orders, RescheduleRequest::DISPATCH, $request->user(), $data['note'] ?? null);
            $sent++;
        }

        return response()->json(['data' => ['sent' => $sent]], 201);
    }

    /** « Relancer » : WhatsApp (premier envoi ou rappel) */
    public function remind(Request $request, RescheduleRequest $rescheduleRequest): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);
        abort_unless($rescheduleRequest->isOpen(), 422, 'Cette demande est déjà réglée.');

        $rescheduleRequest->whatsapp_sent_at === null
            ? $this->reschedules->sendWhatsApp($rescheduleRequest)
            : $this->reschedules->remind($rescheduleRequest);

        return response()->json(['data' => ['reminded_at' => now()]]);
    }

    /** « Choisir la date » / « Tout livrer aujourd'hui » : nouvelle date donnée par l'agence (client prévenu) */
    public function setDate(Request $request): JsonResponse
    {
        Gate::authorize('dispatch', Order::class);
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:300'],
            'order_ids.*' => ['integer', 'distinct'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $orders = Order::query()->whereKey($data['order_ids'])->get();
        $count = $this->reschedules->setDates($orders, $data['date'], $request->user());

        return response()->json(['data' => ['updated' => $count]]);
    }

    private static function day(Order $order): string
    {
        return ($order->delivery_scheduled_date ?? $order->created_at)->toDateString();
    }
}
