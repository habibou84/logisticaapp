<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BillingFormula;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\BillingPlan;
use App\Models\Courier;
use App\Models\CourierRating;
use App\Models\MerchantCourierPreference;
use App\Models\Order;
use App\Services\Ratings\CourierRatings;
use App\Services\Ratings\CourierScores;
use App\Services\Ratings\RatingCriteria;
use App\Services\Ratings\RatingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Notation des livreurs : le client (courses, livreur attitré, livreurs préférés ou écartés), l'équipe
 * (notes, évaluations, qualité des livreurs), le livreur (sa note) et le destinataire (page de suivi).
 */
class CourierRatingController extends Controller
{
    public function __construct(
        private readonly CourierRatings $ratings,
        private readonly CourierScores $scores,
        private readonly RatingSettings $settings,
    ) {}

    /** Critères, motifs et ce que les réglages proposent à l'utilisateur */
    public function criteria(Request $request): JsonResponse
    {
        $s = $this->settings->for($request->user()->company);

        return response()->json(['data' => [
            ...RatingCriteria::all(),
            'settings' => collect($s)->only(['client_course', 'client_edit_hours', 'client_mad_period', 'reason_below', 'client_choice', 'courier_sees_score', 'courier_sees_comments'])->all(),
        ]]);
    }

    // ── Client ──────────────────────────────────────────────────────────────

    /** Note du client sur une course : peut-il noter, sa note, le livreur, sa préférence */
    public function showForOrder(Request $request, Order $order): JsonResponse
    {
        $this->authorizeClient($request, $order);
        $rating = CourierRating::query()->where('order_id', $order->id)->where('source', CourierRating::CLIENT)->first();
        $courier = rescue(fn () => $this->ratings->courierOf($order), null, false);

        return response()->json(['data' => [
            'can_rate' => $this->ratings->canRate($order),
            'rating' => $rating ? $this->ratingData($rating, false) : null,
            'courier' => $courier ? ['id' => $courier->id, 'name' => $courier->user?->name] : null,
            'preference' => $courier ? MerchantCourierPreference::query()->where('merchant_id', $order->merchant_id)->where('courier_id', $courier->id)->value('kind') : null,
        ]]);
    }

    public function rateOrder(Request $request, Order $order): JsonResponse
    {
        $this->authorizeClient($request, $order);
        $rating = $this->ratings->byClient($request->user(), $order, $this->validateClient($request));

        return response()->json(['data' => $this->ratingData($rating, false)], 201);
    }

    /** Courses récentes à noter (accueil du client) */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->merchant_id === null, 403);
        $s = $this->settings->for($user->company);
        if (! $s['client_course']) {
            return response()->json(['data' => [], 'mad' => []]);
        }

        $orders = Order::query()->where('merchant_id', $user->merchant_id)
            ->whereIn('status', [OrderStatus::Delivered->value, OrderStatus::Returned->value])
            ->where(fn ($q) => $q->where('delivered_at', '>=', now()->subHours((int) $s['client_edit_hours']))
                ->orWhere('returned_at', '>=', now()->subHours((int) $s['client_edit_hours'])))
            ->whereDoesntHave('courierRatings', fn ($q) => $q->where('source', CourierRating::CLIENT))
            ->with(['deliveryCourier.user', 'deliveryZone'])
            ->latest('delivered_at')->limit(20)->get();

        return response()->json([
            'data' => $orders->map(fn (Order $o) => [
                'id' => $o->id,
                'tracking_code' => $o->tracking_code,
                'recipient_name' => $o->recipient_name,
                'zone_name' => $o->deliveryZone?->name,
                'courier' => $o->deliveryCourier?->user?->name,
                'at' => $o->delivered_at ?? $o->returned_at,
            ])->values(),
            'mad' => $this->madToRate($request),
        ]);
    }

    /** Livreur attitré (MAD) : note de la semaine ou du mois */
    public function rateMadCourier(Request $request, Courier $courier): JsonResponse
    {
        abort_if($request->user()->merchant_id === null, 403);
        $rating = $this->ratings->byMadClient($request->user(), $courier, $this->validateClient($request));

        return response()->json(['data' => $this->ratingData($rating, false)], 201);
    }

    /** Livreurs préférés ou écartés par le client (si l'entreprise le permet) */
    public function preferences(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->merchant_id === null, 403);

        return response()->json([
            'enabled' => (bool) $this->settings->for($user->company)['client_choice'],
            'data' => MerchantCourierPreference::query()->where('merchant_id', $user->merchant_id)->with('courier.user')->get()
                ->map(fn (MerchantCourierPreference $p) => ['courier_id' => $p->courier_id, 'name' => $p->courier?->user?->name, 'kind' => $p->kind])->values(),
        ]);
    }

    public function setPreference(Request $request, Courier $courier): JsonResponse
    {
        $user = $request->user();
        abort_if($user->merchant_id === null, 403);
        if (! $this->settings->for($user->company)['client_choice']) {
            throw new BusinessRuleException('Le choix des livreurs n\'est pas proposé.', 'kind');
        }
        $data = $request->validate(['kind' => ['nullable', Rule::in([MerchantCourierPreference::PREFERRED, MerchantCourierPreference::EXCLUDED])]]);
        // Seulement un livreur qui a déjà fait une course du client
        abort_unless(Order::query()->where('merchant_id', $user->merchant_id)
            ->where(fn ($q) => $q->where('delivery_courier_id', $courier->id)->orWhere('pickup_courier_id', $courier->id))->exists(), 403);

        if (empty($data['kind'])) {
            MerchantCourierPreference::query()->where('merchant_id', $user->merchant_id)->where('courier_id', $courier->id)->delete();
        } else {
            // Un seul livreur préféré
            if ($data['kind'] === MerchantCourierPreference::PREFERRED) {
                MerchantCourierPreference::query()->where('merchant_id', $user->merchant_id)->where('kind', MerchantCourierPreference::PREFERRED)->where('courier_id', '!=', $courier->id)->delete();
            }
            MerchantCourierPreference::query()->updateOrCreate(
                ['merchant_id' => $user->merchant_id, 'courier_id' => $courier->id],
                ['company_id' => $user->company_id, 'kind' => $data['kind']],
            );
        }

        return $this->preferences($request);
    }

    // ── Équipe ──────────────────────────────────────────────────────────────

    /** Qualité des livreurs : note et détail de chacun */
    public function quality(Request $request): JsonResponse
    {
        $this->authorizeTeam($request);
        $s = $this->settings->for($request->user()->company);

        $rows = Courier::query()->with('user')->whereHas('user', fn ($q) => $q->where('status', 'active'))->get()
            ->map(function (Courier $c) use ($s) {
                $score = $this->scores->for($c);

                return [
                    'id' => $c->id,
                    'name' => $c->user?->name,
                    'phone' => $c->user?->phone,
                    'score' => $score['score'],
                    'new' => $score['new'],
                    'auto' => $score['auto']['score'],
                    'client' => $score['client']['average'],
                    'client_count' => $score['client']['count'],
                    'team' => $score['team']['average'],
                    'team_count' => $score['team']['count'],
                    'bad_last_7_days' => $score['bad_last_7_days'],
                    'alert' => ($score['score'] !== null && $score['score'] < (float) $s['alert_below'])
                        || ((int) $s['alert_bad_count'] > 0 && $score['bad_last_7_days'] >= (int) $s['alert_bad_count']),
                ];
            })
            ->sortBy([fn ($a, $b) => ($b['score'] ?? -1) <=> ($a['score'] ?? -1)])->values();

        return response()->json(['data' => $rows, 'settings' => collect($s)->only(['window_days', 'alert_below', 'alert_bad_count'])]);
    }

    /** Note d'un livreur : détail, évolution, dernières notes */
    public function courier(Request $request, Courier $courier): JsonResponse
    {
        $this->authorizeTeam($request);

        return response()->json(['data' => [
            'courier' => ['id' => $courier->id, 'name' => $courier->user?->name],
            ...$this->scores->for($courier),
            'trend' => $this->scores->trend($courier),
            'ratings' => CourierRating::query()->where('courier_id', $courier->id)->with(['author', 'merchant', 'order'])
                ->latest()->limit(50)->get()->map(fn (CourierRating $r) => $this->ratingData($r, true))->values(),
        ]]);
    }

    public function rateByTeam(Request $request, Courier $courier): JsonResponse
    {
        $this->authorizeTeam($request);
        $data = $request->validate([
            'order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')->where('company_id', $request->user()->company_id)],
            'stars' => ['required', 'integer', 'between:1,5'],
            'criteria' => ['nullable', 'array'],
            'criteria.*' => ['integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [], ['stars' => 'note', 'comment' => 'commentaire']);
        $order = isset($data['order_id']) ? Order::query()->findOrFail($data['order_id']) : null;

        return response()->json(['data' => $this->ratingData($this->ratings->byTeam($request->user(), $courier, $order, $data), true)], 201);
    }

    /** Notes d'une course (fiche course de l'équipe) */
    public function orderRatings(Request $request, Order $order): JsonResponse
    {
        $this->authorizeTeam($request);

        return response()->json(['data' => CourierRating::query()->where('order_id', $order->id)->with(['author', 'merchant', 'courier.user'])->latest()->get()
            ->map(fn (CourierRating $r) => [...$this->ratingData($r, true), 'courier' => ['id' => $r->courier_id, 'name' => $r->courier?->user?->name]])->values()]);
    }

    public function neutralize(Request $request, CourierRating $rating): JsonResponse
    {
        $this->authorizeTeam($request, Permission::UsersManage);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'motif']);

        return response()->json(['data' => $this->ratingData($this->ratings->neutralize($request->user(), $rating, $data['reason']), true)]);
    }

    public function restore(Request $request, CourierRating $rating): JsonResponse
    {
        $this->authorizeTeam($request, Permission::UsersManage);

        return response()->json(['data' => $this->ratingData($this->ratings->restore($rating), true)]);
    }

    // ── Livreur ─────────────────────────────────────────────────────────────

    /** Le livreur voit sa note et, selon les réglages, les commentaires (sans le nom du client) */
    public function mine(Request $request): JsonResponse
    {
        $courier = $request->user()->courier;
        abort_if($courier === null, 403);
        $s = $this->settings->for($request->user()->company);
        if (! $s['courier_sees_score']) {
            return response()->json(['data' => null]);
        }
        $score = $this->scores->for($courier);

        return response()->json(['data' => [
            'score' => $score['score'],
            'new' => $score['new'],
            'auto' => $score['auto'],
            'client' => collect($score['client'])->only(['average', 'count', 'criteria', 'reasons'])->all(),
            'team' => collect($score['team'])->only(['average', 'count', 'criteria'])->all(),
            'comments' => $s['courier_sees_comments']
                ? CourierRating::query()->where('courier_id', $courier->id)->counted()->whereNotNull('comment')
                    ->where('source', '!=', CourierRating::TEAM)->with('merchant')->latest()->limit(20)->get()
                    ->map(fn (CourierRating $r) => [
                        'stars' => $r->stars,
                        'comment' => $r->comment,
                        'from' => $s['comments_anonymous'] ? null : $r->merchant?->business_name,
                        'at' => $r->created_at,
                    ])->values()
                : [],
        ]]);
    }

    // ── Destinataire ────────────────────────────────────────────────────────

    public function rateByRecipient(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'stars' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:500'],
            'delivery_code' => ['required', 'string', 'max:10'],
        ], [], ['stars' => 'note', 'delivery_code' => 'code de livraison']);
        $order = Order::withoutGlobalScopes()->whereNull('deleted_at')->where('tracking_code', mb_strtoupper(trim($code)))->with('company')->first();
        abort_if($order === null, 404);
        if (! hash_equals((string) $order->delivery_code, trim($data['delivery_code']))) {
            throw new BusinessRuleException('Code de livraison incorrect.', 'delivery_code');
        }
        $this->ratings->byRecipient($order, $data);

        return response()->json(['message' => 'Merci pour votre avis !'], 201);
    }

    // ── Outils ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function validateClient(Request $request): array
    {
        return $request->validate([
            'stars' => ['required', 'integer', 'between:1,5'],
            'criteria' => ['nullable', 'array'],
            'criteria.*' => ['string', Rule::in(array_keys(RatingCriteria::CLIENT))],
            'reasons' => ['nullable', 'array'],
            'reasons.*' => ['string', Rule::in(array_keys(RatingCriteria::REASONS))],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [], ['stars' => 'note', 'reasons' => 'motif', 'comment' => 'commentaire']);
    }

    /**
     * Livreurs attitrés (MAD) du client et leur note de la période en cours.
     *
     * @return list<array<string, mixed>>
     */
    private function madToRate(Request $request): array
    {
        $user = $request->user();
        $s = $this->settings->for($user->company);
        if ($s['client_mad_period'] === 'off') {
            return [];
        }
        $start = $s['client_mad_period'] === 'weekly' ? now()->startOfWeek() : now()->startOfMonth();

        return BillingPlan::withoutGlobalScopes()->where('merchant_id', $user->merchant_id)
            ->where('formula', BillingFormula::MadContract->value)->activeOn(now()->toDateString())->with('couriers.user')->get()
            ->flatMap(fn (BillingPlan $p) => $p->couriers)->unique('id')
            ->map(function (Courier $c) use ($user, $start, $s) {
                $rating = CourierRating::query()->where('courier_id', $c->id)->where('merchant_id', $user->merchant_id)
                    ->where('scope', CourierRating::PERIOD)->whereDate('period_start', $start->toDateString())->first();

                return [
                    'courier_id' => $c->id,
                    'name' => $c->user?->name,
                    'period' => $s['client_mad_period'] === 'weekly' ? 'cette semaine' : $start->locale('fr')->isoFormat('MMMM YYYY'),
                    'rating' => $rating ? $this->ratingData($rating, false) : null,
                ];
            })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function ratingData(CourierRating $r, bool $staff): array
    {
        return [
            'id' => $r->id,
            'source' => $r->source,
            'scope' => $r->scope,
            'stars' => $r->stars,
            'criteria' => $r->criteria ?? [],
            'reasons' => $r->reasons ?? [],
            'comment' => $r->comment,
            'period_start' => $r->period_start?->toDateString(),
            'period_end' => $r->period_end?->toDateString(),
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
            ...($staff ? [
                'weight' => $r->weight,
                'author' => $r->author?->name,
                'merchant' => $r->merchant?->business_name,
                'order' => $r->order ? ['id' => $r->order->id, 'tracking_code' => $r->order->tracking_code] : null,
                'neutralized_at' => $r->neutralized_at,
                'neutralized_reason' => $r->neutralized_reason,
            ] : []),
        ];
    }

    private function authorizeClient(Request $request, Order $order): void
    {
        abort_unless($request->user()->merchant_id !== null && $request->user()->merchant_id === $order->merchant_id, 403);
    }

    private function authorizeTeam(Request $request, Permission $permission = Permission::OrdersDispatch): void
    {
        $user = $request->user();
        abort_unless($user->merchant_id === null && ! $user->isCourier() && ($user->can($permission->value) || $user->can(Permission::UsersManage->value)), 403);
    }
}
