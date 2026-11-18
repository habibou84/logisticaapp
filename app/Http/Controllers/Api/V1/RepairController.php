<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\RepairResource;
use App\Models\Mechanic;
use App\Models\Repair;
use App\Models\RepairFile;
use App\Models\RepairPurchase;
use App\Services\Workshop\RepairDispatcher;
use App\Services\Workshop\Repairs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Atelier côté équipe : les interventions (en attente, en cours, terminées), les mécaniciens, l'attribution à la
 * main, l'annulation ; les achats sur place à accorder (droit « Accorder les achats de pièces sur place »).
 */
class RepairController extends Controller
{
    public function __construct(
        private readonly RepairDispatcher $dispatcher,
        private readonly Repairs $repairs,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tab = $request->query('tab', 'open');
        $search = trim((string) $request->query('q', ''));
        $query = Repair::query()->with(['mechanic.user', 'courier.user', 'vehicle'])
            ->when($tab === 'waiting', fn ($q) => $q->whereIn('status', Repair::WAITING))
            ->when($tab === 'open', fn ($q) => $q->open())
            ->when($tab === 'done', fn ($q) => $q->whereIn('status', [Repair::DONE, Repair::CANCELLED]))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhereHas('vehicle', fn ($v) => $v->where('plate', 'like', "%{$search}%"))
                ->orWhereHas('courier.user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                ->orWhereHas('mechanic.user', fn ($u) => $u->where('name', 'like', "%{$search}%"))));
        $repairs = $tab === 'done' ? $query->latest('updated_at')->paginate(30) : $query->latest('id')->paginate(50);

        $today = now()->startOfDay();
        $doneToday = Repair::where('status', Repair::DONE)->where('finished_at', '>=', $today)->get();

        return response()->json([
            'data' => RepairResource::collection($repairs->items()),
            'meta' => ['current_page' => $repairs->currentPage(), 'last_page' => $repairs->lastPage(), 'total' => $repairs->total()],
            'counts' => [
                'waiting' => Repair::whereIn('status', Repair::WAITING)->count(),
                'searching' => Repair::where('status', Repair::SEARCHING)->count(),
                'in_progress' => Repair::whereIn('status', Repair::IN_PROGRESS)->count(),
                'today' => Repair::where('created_at', '>=', $today)->count(),
                'purchases' => $request->user()->can(Permission::RepairsPurchases->value) ? RepairPurchase::where('status', RepairPurchase::PENDING)->count() : null,
            ],
            // Délais moyens des interventions terminées aujourd'hui (minutes)
            'averages' => [
                'response' => $this->average($doneToday, 'created_at', 'accepted_at'),
                'arrival' => $this->average($doneToday, 'accepted_at', 'arrived_at'),
                'downtime' => $this->average($doneToday, 'created_at', 'finished_at'),
            ],
        ]);
    }

    /** Badge du menu : pannes sans mécanicien ou en attente de réponse, achats sur place à accorder */
    public function counts(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'waiting' => $user->can(Permission::RepairsManage->value) ? Repair::whereIn('status', Repair::WAITING)->count() : 0,
            'searching' => $user->can(Permission::RepairsManage->value) ? Repair::where('status', Repair::SEARCHING)->count() : 0,
            'purchases' => $user->can(Permission::RepairsPurchases->value) ? RepairPurchase::where('status', RepairPurchase::PENDING)->count() : 0,
        ]]);
    }

    public function show(Repair $repair): RepairResource
    {
        return RepairResource::make($repair->load(RepairResource::RELATIONS));
    }

    /** Nouvelle intervention (problème vu au contrôle de départ, véhicule au dépôt…) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'title' => ['required', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:1000'],
            'severity' => ['nullable', 'in:info,hindrance,blocking,urgent'],
            'mechanic_id' => ['nullable', 'integer'],
        ], [], ['title' => 'panne', 'vehicle_id' => 'véhicule']);
        $repair = $this->dispatcher->create($request->user(), $data);

        return response()->json(['data' => RepairResource::make($repair->fresh()->load(RepairResource::RELATIONS))], 201);
    }

    public function assign(Request $request, Repair $repair): RepairResource
    {
        $data = $request->validate(['mechanic_id' => ['required', 'integer']], [], ['mechanic_id' => 'mécanicien']);
        $mechanic = Mechanic::query()->findOrFail($data['mechanic_id']);
        $this->dispatcher->assign($request->user(), $repair, $mechanic);

        return RepairResource::make($repair->fresh()->load(RepairResource::RELATIONS));
    }

    public function cancel(Request $request, Repair $repair): RepairResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $this->dispatcher->cancel($request->user(), $repair, $data['reason'] ?? null);

        return RepairResource::make($repair->fresh()->load(RepairResource::RELATIONS));
    }

    /** Mécaniciens : en service, position, interventions en cours (pour attribuer) */
    public function mechanics(Request $request): JsonResponse
    {
        $repair = $request->query('repair') ? Repair::query()->find($request->query('repair')) : null;
        $mechanics = Mechanic::query()->with('user')->withCount('activeRepairs')->get()
            ->filter(fn (Mechanic $m) => $m->user !== null && $m->user->isMechanic() && $m->user->status === UserStatus::Active);
        $order = $repair ? $this->dispatcher->candidates($repair)->pluck('distance_km', 'id') : collect();

        return response()->json(['data' => $mechanics->map(fn (Mechanic $m) => [
            'id' => $m->id,
            'name' => $m->user->name,
            'phone' => $m->user->phone,
            'is_available' => $m->is_available,
            'available_since' => $m->available_since,
            'last_location_at' => $m->last_location_at,
            'active_repairs' => $m->active_repairs_count,
            'distance_km' => $order[$m->id] ?? null,
        ])->sortBy(fn ($m) => [! $m['is_available'], $m['distance_km'] === null, $m['distance_km'] ?? 0, $m['active_repairs'], $m['name']])->values()]);
    }

    public function file(Repair $repair, RepairFile $file): StreamedResponse
    {
        abort_unless($file->repair_id === $repair->id, 404);

        return Storage::disk($file->disk)->response($file->path);
    }

    public function receipt(Repair $repair, RepairPurchase $purchase): StreamedResponse
    {
        abort_unless($purchase->repair_id === $repair->id && $purchase->receipt_path, 404);

        return Storage::disk('local')->response($purchase->receipt_path);
    }

    /** Achats sur place en attente de réponse */
    public function purchases(): JsonResponse
    {
        $purchases = RepairPurchase::query()->with(['repair.vehicle', 'repair.courier.user', 'mechanic.user'])
            ->where('status', RepairPurchase::PENDING)->latest('id')->get();

        return response()->json(['data' => $purchases->map(fn (RepairPurchase $p) => [
            'id' => $p->id,
            'repair_id' => $p->repair_id,
            'repair_title' => $p->repair?->title,
            'vehicle' => $p->repair?->vehicle?->plate,
            'courier' => $p->repair?->courier?->user?->name,
            'mechanic' => $p->mechanic?->user?->name,
            'label' => $p->label,
            'quantity' => $p->quantity,
            'estimated_amount' => $p->estimated_amount,
            'reason' => $p->reason,
            'created_at' => $p->created_at,
        ])->values()]);
    }

    public function decidePurchase(Request $request, RepairPurchase $purchase): JsonResponse
    {
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'max_amount' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['max_amount' => 'montant maximum']);
        $purchase = $this->repairs->decidePurchase($request->user(), $purchase, $data['approve'], $data['max_amount'] ?? null, $data['note'] ?? null);

        return response()->json(['data' => ['id' => $purchase->id, 'status' => $purchase->status, 'max_amount' => $purchase->max_amount]]);
    }

    /** Délai moyen en minutes entre deux étapes */
    private function average($repairs, string $from, string $to): ?int
    {
        $values = $repairs->filter(fn (Repair $r) => $r->{$from} && $r->{$to})
            ->map(fn (Repair $r) => $r->{$from}->diffInMinutes($r->{$to}));

        return $values->isEmpty() ? null : (int) round($values->avg());
    }
}
