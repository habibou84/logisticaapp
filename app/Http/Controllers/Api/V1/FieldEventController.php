<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\FieldEventResource;
use App\Models\Courier;
use App\Models\FieldEvent;
use App\Models\FieldEventFile;
use App\Services\FieldEvents\FieldEventActions;
use App\Services\FieldEvents\FieldEvents;
use App\Services\FieldEvents\FieldEventSettings;
use App\Services\FieldEvents\FieldEventStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Événements terrain côté équipe (Remontées terrain › Événements) : liste, prise en charge, échanges avec le
 * livreur, résolution, et actions sur ses courses (les confier à un autre, retirer ses ramassages, prévenir
 * les clients).
 */
class FieldEventController extends Controller
{
    public function __construct(
        private readonly FieldEvents $events,
        private readonly FieldEventActions $actions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'state' => ['nullable', Rule::in(['open', 'resolved', 'all'])],
            'type' => ['nullable', 'string', 'max:40'],
            'courier_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $events = FieldEvent::query()->with(FieldEventResource::RELATIONS)
            ->when($request->input('state', 'open') === 'open', fn ($q) => $q->unresolved())
            ->when($request->input('state') === 'resolved', fn ($q) => $q->where('status', FieldEvent::RESOLVED))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('courier_id'), fn ($q) => $q->where('courier_id', $request->integer('courier_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            // Les plus graves d'abord parmi les événements en cours, puis les plus récents
            ->orderByRaw("CASE WHEN status = 'resolved' THEN 1 ELSE 0 END")
            ->orderByRaw("CASE severity WHEN 'urgent' THEN 0 WHEN 'blocking' THEN 1 WHEN 'hindrance' THEN 2 ELSE 3 END")
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 30));

        return response()->json([
            'data' => FieldEventResource::collection($events->items()),
            'meta' => ['current_page' => $events->currentPage(), 'last_page' => $events->lastPage(), 'total' => $events->total()],
            'open' => $this->openCount(),
            'types' => app(FieldEventSettings::class)->for($request->user()->company)['types'],
        ]);
    }

    /**
     * Statistiques de la période (Statistiques › Terrain, fiche du livreur).
     */
    public function stats(Request $request, FieldEventStats $stats): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'courier_id' => ['nullable', 'integer'],
        ]);

        return response()->json(['data' => $stats->summary($data['from'], $data['to'], $data['courier_id'] ?? null)]);
    }

    public function counts(): JsonResponse
    {
        return response()->json(['data' => ['open' => $this->openCount()]]);
    }

    public function show(FieldEvent $event): FieldEventResource
    {
        // Messages du livreur lus par l'équipe
        $event->messages()->where('from_courier', true)->whereNull('read_at')->update(['read_at' => now()]);

        return FieldEventResource::make($event->load(FieldEventResource::RELATIONS));
    }

    public function take(Request $request, FieldEvent $event): JsonResponse
    {
        $this->events->take($request->user(), $event);

        return $this->respond($event);
    }

    public function resolve(Request $request, FieldEvent $event): JsonResponse
    {
        $data = $request->validate(['resolution' => ['nullable', 'string', 'max:1000']]);
        $this->events->resolve($request->user(), $event, $data['resolution'] ?? null);

        return $this->respond($event);
    }

    public function reopen(FieldEvent $event): JsonResponse
    {
        $this->events->reopen($event);

        return $this->respond($event);
    }

    public function message(Request $request, FieldEvent $event): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:1000']], [], ['body' => 'message']);
        $this->events->message($request->user(), $event, $data['body']);

        return $this->respond($event);
    }

    public function candidates(FieldEvent $event): JsonResponse
    {
        return response()->json(['data' => $this->actions->candidates($event)]);
    }

    public function reassign(Request $request, FieldEvent $event): JsonResponse
    {
        $data = $request->validate([
            'courier_id' => ['required', 'integer', Rule::exists('couriers', 'id')->where('company_id', $request->user()->company_id)],
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer'],
            'notify' => ['sometimes', 'boolean'],
        ], [], ['courier_id' => 'livreur', 'order_ids' => 'courses']);

        $result = $this->actions->reassign($request->user(), $event, $data['order_ids'], Courier::findOrFail($data['courier_id']), $data['notify'] ?? true);

        return $this->respond($event, ['result' => $result]);
    }

    public function release(Request $request, FieldEvent $event): JsonResponse
    {
        return $this->respond($event, ['result' => $this->actions->release($request->user(), $event)]);
    }

    public function notifyClients(FieldEvent $event): JsonResponse
    {
        return $this->respond($event, ['result' => $this->actions->notifyClients($event)]);
    }

    public function file(FieldEvent $event, FieldEventFile $file): StreamedResponse
    {
        abort_unless($file->field_event_id === $event->id, 404);

        return Storage::disk($file->disk)->response($file->path);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function respond(FieldEvent $event, array $extra = []): JsonResponse
    {
        return response()->json([
            'data' => FieldEventResource::make($event->fresh()->load(FieldEventResource::RELATIONS)),
            'open' => $this->openCount(),
            ...$extra,
        ]);
    }

    private function openCount(): int
    {
        return FieldEvent::query()->where('status', FieldEvent::OPEN)->count();
    }
}
