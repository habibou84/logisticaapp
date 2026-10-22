<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderEventType;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\FieldReportReview;
use App\Models\OrderEvent;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Remontées terrain : notes et problèmes enregistrés par les livreurs, à consulter
 * et à marquer comme traités par le dispatch.
 */
class FieldReportController extends Controller
{
    private const KINDS = [
        'incident' => [OrderEventType::Incident, OrderEventType::StatusChanged],
        'note' => [OrderEventType::Note],
        'refusal' => [OrderEventType::AssignmentRefused],
        'expense' => [OrderEventType::ExpenseAdded],
    ];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'state' => ['nullable', Rule::in(['open', 'handled', 'all'])],
            'kind' => ['nullable', Rule::in(array_keys(self::KINDS))],
            'courier_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $reports = $this->query($request)
            ->with([
                'order' => fn ($q) => $q->with(['merchant', 'deliveryZone', 'pickupZone']),
                'incidentReason', 'attachments', 'review.handler', 'replies.sender',
            ])
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 30));

        $couriers = Courier::with('user')->get()->keyBy('user_id');

        return response()->json([
            'data' => collect($reports->items())->map(fn (OrderEvent $e) => $this->present($e, $couriers->get($e->actor_id))),
            'meta' => ['current_page' => $reports->currentPage(), 'last_page' => $reports->lastPage(), 'total' => $reports->total()],
            'open' => $this->openCounts(),
        ]);
    }

    /**
     * Nombre de remontées à traiter (badge du menu), par catégorie.
     */
    public function counts(): JsonResponse
    {
        return response()->json(['data' => $this->openCounts()]);
    }

    public function handle(Request $request, OrderEvent $event): JsonResponse
    {
        $this->ensureFieldReport($event);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);

        FieldReportReview::updateOrCreate(['order_event_id' => $event->id], [
            'company_id' => $request->user()->company_id,
            'handled_by' => $request->user()->id,
            'comment' => $data['comment'] ?? null,
            'handled_at' => now(),
        ]);

        return response()->json(['data' => $this->present($event->fresh(['order.merchant', 'order.deliveryZone', 'order.pickupZone', 'incidentReason', 'attachments', 'review.handler', 'replies.sender'])), 'open' => $this->openCounts()]);
    }

    public function reopen(OrderEvent $event): JsonResponse
    {
        $this->ensureFieldReport($event);
        FieldReportReview::where('order_event_id', $event->id)->delete();

        return response()->json(['data' => $this->present($event->fresh(['order.merchant', 'order.deliveryZone', 'order.pickupZone', 'incidentReason', 'attachments', 'review.handler', 'replies.sender'])), 'open' => $this->openCounts()]);
    }

    private function query(Request $request): Builder
    {
        $courierUserId = $request->filled('courier_id') ? Courier::find($request->integer('courier_id'))?->user_id ?? 0 : null;

        return OrderEvent::query()
            ->fieldReports()
            // Courses de l'entreprise (portée globale du modèle Order)
            ->whereHas('order')
            ->when($request->input('state', 'open') === 'open', fn ($q) => $q->whereDoesntHave('review'))
            ->when($request->input('state') === 'handled', fn ($q) => $q->whereHas('review'))
            ->when($request->filled('kind'), fn ($q) => $q->whereIn('type', array_map(fn ($t) => $t->value, self::KINDS[$request->string('kind')->value()])))
            ->when($courierUserId !== null, fn ($q) => $q->where('actor_id', $courierUserId))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $raw = trim($request->string('search'));
                $term = '%'.mb_strtolower($raw).'%';
                $phone = PhoneNumber::normalize($raw);
                $q->where(fn ($q) => $q
                    ->whereRaw('LOWER(note) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(actor_name) LIKE ?', [$term])
                    ->orWhereHas('order', fn ($o) => $o->whereRaw('LOWER(tracking_code) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(recipient_name) LIKE ?', [$term])
                        ->orWhere('recipient_phone', 'like', $phone ? "%{$phone}%" : $term)));
            });
    }

    /**
     * @return array<string, int>
     */
    private function openCounts(): array
    {
        $events = OrderEvent::query()->fieldReports()->whereHas('order')->whereDoesntHave('review')
            ->where('created_at', '>=', now()->subDays(30))
            ->get(['id', 'type']);

        $counts = ['total' => $events->count()];
        foreach (array_keys(self::KINDS) as $kind) {
            $counts[$kind] = $events->filter(fn (OrderEvent $e) => $e->fieldKind() === $kind)->count();
        }

        return $counts;
    }

    private function ensureFieldReport(OrderEvent $event): void
    {
        // Course d'une autre entreprise : introuvable
        abort_unless(OrderEvent::whereKey($event->id)->whereHas('order')->exists(), 404);

        if (! OrderEvent::whereKey($event->id)->fieldReports()->exists()) {
            throw new BusinessRuleException('Ce n\'est pas une remontée terrain.');
        }
    }

    private function present(OrderEvent $e, ?Courier $courier = null): array
    {
        $order = $e->order;

        return [
            'id' => $e->id,
            'kind' => $e->fieldKind(),
            'type' => $e->type->value,
            'status' => $e->to_status?->value,
            'status_label' => $e->to_status?->label(),
            'incident' => $e->incidentReason?->label,
            'note' => $e->note,
            'rescheduled_to' => $e->rescheduled_to?->toDateString(),
            'expense' => $e->type === OrderEventType::ExpenseAdded ? ($e->meta['amount'] ?? null) : null,
            'visible_to_merchant' => $e->visible_to_merchant,
            'lat' => $e->lat,
            'lng' => $e->lng,
            'photos' => $e->attachments->count(),
            'created_at' => $e->created_at,
            'courier' => [
                'id' => $courier?->id,
                'name' => $e->actor_name,
                'phone' => $courier?->user?->phone,
            ],
            'order' => $order ? [
                'id' => $order->id,
                'tracking_code' => $order->tracking_code,
                'status' => $order->status->value,
                'status_label' => $order->statusLabel(),
                'merchant' => $order->merchant?->business_name,
                'recipient_name' => $order->recipient_name,
                'recipient_phone' => $order->recipient_phone,
                'zone' => $order->deliveryZone?->name,
            ] : null,
            'replies' => $e->relationLoaded('replies') ? $e->replies->map(fn ($m) => $m->present())->values() : [],
            'review' => $e->review ? [
                'handled_at' => $e->review->handled_at,
                'handled_by' => $e->review->handler?->name,
                'comment' => $e->review->comment,
            ] : null,
        ];
    }
}
