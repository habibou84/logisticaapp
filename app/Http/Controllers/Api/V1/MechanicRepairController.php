<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\RepairResource;
use App\Models\FieldEvent;
use App\Models\FieldEventFile;
use App\Models\Repair;
use App\Models\RepairFile;
use App\Models\RepairPurchase;
use App\Services\FieldEvents\FieldEvents;
use App\Services\Workshop\RepairDispatcher;
use App\Services\Workshop\Repairs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Une panne côté mécanicien : accepter ou refuser, partir, arriver, diagnostiquer, photographier, demander un achat
 * sur place, échanger avec le livreur, clôturer. Les heures envoyées (« at ») sont celles du téléphone au clic.
 */
class MechanicRepairController extends Controller
{
    public function __construct(
        private readonly RepairDispatcher $dispatcher,
        private readonly Repairs $repairs,
    ) {}

    public function show(Request $request, Repair $repair): RepairResource
    {
        $this->ensureVisible($request, $repair);

        return $this->present($repair);
    }

    public function accept(Request $request, Repair $repair): RepairResource
    {
        $this->ensureVisible($request, $repair);

        return $this->present($this->dispatcher->accept($request->user(), $repair, $this->at($request)));
    }

    public function decline(Request $request, Repair $repair): JsonResponse
    {
        $this->ensureVisible($request, $repair);
        $this->dispatcher->decline($request->user(), $repair);

        return response()->json(['message' => 'La panne est proposée à un autre mécanicien.']);
    }

    public function depart(Request $request, Repair $repair): RepairResource
    {
        $this->ensureVisible($request, $repair);

        return $this->present($this->repairs->depart($request->user(), $repair, $this->at($request)));
    }

    public function arrive(Request $request, Repair $repair): RepairResource
    {
        $this->ensureVisible($request, $repair);
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        return $this->present($this->repairs->arrive($request->user(), $repair, $this->at($request), $data['lat'] ?? null, $data['lng'] ?? null));
    }

    public function start(Request $request, Repair $repair): RepairResource
    {
        $this->ensureVisible($request, $repair);
        $data = $request->validate([
            'category' => ['required', 'string', 'max:40'],
            'part' => ['nullable', 'string', 'max:120'],
            'diagnosis' => ['nullable', 'string', 'max:2000'],
            'decision' => ['required', 'string', 'in:'.implode(',', array_keys(Repair::DECISIONS))],
            'at' => ['nullable', 'string', 'max:40'],
        ], [], ['category' => 'type de panne', 'part' => 'pièce touchée', 'decision' => 'décision']);

        return $this->present($this->repairs->start($request->user(), $repair, $data));
    }

    public function photos(Request $request, Repair $repair): RepairResource
    {
        $this->ensureVisible($request, $repair);
        $data = $request->validate([
            'kind' => ['required', 'in:before,after'],
            'photos' => ['required', 'array', 'min:1', 'max:6'],
            'photos.*' => ['image', 'max:8192'],
        ], [], ['photos.*' => 'photo']);
        $this->repairs->addPhotos($request->user(), $repair, $data['kind'], $request->file('photos', []));

        return $this->present($repair->fresh());
    }

    public function deletePhoto(Request $request, Repair $repair, RepairFile $file): RepairResource
    {
        $this->ensureVisible($request, $repair);
        $this->repairs->deletePhoto($request->user(), $repair, $file);

        return $this->present($repair->fresh());
    }

    public function purchase(Request $request, Repair $repair): RepairResource
    {
        $this->ensureVisible($request, $repair);
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'estimated_amount' => ['required', 'integer', 'min:1', 'max:10000000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [], ['label' => 'pièce', 'estimated_amount' => 'prix estimé']);
        $this->repairs->requestPurchase($request->user(), $repair, $data);

        return $this->present($repair->fresh());
    }

    public function close(Request $request, Repair $repair): RepairResource
    {
        $this->ensureVisible($request, $repair);
        $data = $request->validate([
            'parts' => ['nullable', 'array', 'max:40'],
            'parts.*.label' => ['nullable', 'string', 'max:120'],
            'parts.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'parts.*.unit_price' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'purchases' => ['nullable', 'array'],
            'purchases.*' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'receipts' => ['nullable', 'array'],
            'receipts.*' => ['image', 'max:8192'],
            'cause' => ['required', 'string', 'in:'.implode(',', array_keys(Repair::CAUSES))],
            'decision' => ['nullable', 'string', 'in:'.implode(',', array_keys(Repair::DECISIONS))],
            'odometer' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'garage' => ['nullable', 'string', 'max:120'],
            'garage_amount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'courier_can_resume' => ['sometimes', 'boolean'],
            'at' => ['nullable', 'string', 'max:40'],
        ], [], ['cause' => 'cause', 'odometer' => 'compteur', 'parts.*.label' => 'pièce', 'parts.*.unit_price' => 'prix', 'purchases.*' => 'prix payé']);

        return $this->present($this->repairs->close($request->user(), $repair, $data, $request->file('receipts', [])));
    }

    /** Message au livreur (fil de son signalement) */
    public function message(Request $request, Repair $repair, FieldEvents $events): RepairResource
    {
        $this->ensureVisible($request, $repair);
        abort_unless($repair->mechanic_id === $request->user()->mechanic?->id && $repair->field_event_id, 422, 'Pas d\'échange possible sur cette panne.');
        $data = $request->validate(['body' => ['required', 'string', 'max:1000']], [], ['body' => 'message']);
        $events->message($request->user(), FieldEvent::findOrFail($repair->field_event_id), $data['body']);

        return $this->present($repair->fresh());
    }

    public function file(Request $request, Repair $repair, RepairFile $file): StreamedResponse
    {
        $this->ensureVisible($request, $repair);
        abort_unless($file->repair_id === $repair->id, 404);

        return Storage::disk($file->disk)->response($file->path);
    }

    /** Photo ou note vocale du signalement du livreur */
    public function eventFile(Request $request, Repair $repair, FieldEventFile $file): StreamedResponse
    {
        $this->ensureVisible($request, $repair);
        abort_unless($repair->field_event_id !== null && $file->field_event_id === $repair->field_event_id, 404);

        return Storage::disk($file->disk)->response($file->path);
    }

    /** Facture d'un achat sur place */
    public function receipt(Request $request, Repair $repair, RepairPurchase $purchase): StreamedResponse
    {
        $this->ensureVisible($request, $repair);
        abort_unless($purchase->repair_id === $repair->id && $purchase->receipt_path, 404);

        return Storage::disk('local')->response($purchase->receipt_path);
    }

    private function present(Repair $repair): RepairResource
    {
        $repair->unsetRelations();
        $repair->load(RepairResource::RELATIONS);
        $repair->fieldEvent?->messages()->where('from_courier', true)->whereNull('read_at')->update(['read_at' => now()]);

        return RepairResource::make($repair);
    }

    /** Ses pannes, et les pannes sans mécanicien qu'il peut prendre */
    private function ensureVisible(Request $request, Repair $repair): void
    {
        $this->dispatcher->mechanicOf($request->user());
        abort_unless($this->repairs->canSee($request->user(), $repair), 404);
    }

    private function at(Request $request): ?string
    {
        $at = $request->input('at');

        return is_string($at) ? mb_substr($at, 0, 40) : null;
    }
}
