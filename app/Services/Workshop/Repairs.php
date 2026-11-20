<?php

namespace App\Services\Workshop;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\FieldEvent;
use App\Models\Repair;
use App\Models\RepairFile;
use App\Models\RepairPurchase;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLog;
use App\Notifications\RepairAlert;
use App\Services\FieldEvents\FieldEventAlerts;
use App\Services\FieldEvents\FieldEvents;
use App\Services\Vehicles\Fleet;
use App\Services\Vehicles\VehicleLogs;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Travail du mécanicien sur une intervention acceptée : départ, arrivée (heure et position), diagnostic avec photos,
 * achat d'une pièce sur place (accord de l'administrateur), clôture. À la clôture, le coût entre au carnet du
 * véhicule et, si le livreur peut repartir, l'événement terrain est réglé (livreur et véhicule remis en service).
 */
class Repairs
{
    public function __construct(
        private readonly WorkshopSettings $settings,
        private readonly RepairDispatcher $dispatcher,
        private readonly FieldEvents $fieldEvents,
        private readonly FieldEventAlerts $fieldAlerts,
        private readonly VehicleLogs $logs,
        private readonly Fleet $fleet,
    ) {}

    /** « Je pars » : le livreur voit que le mécanicien arrive */
    public function depart(User $user, Repair $repair, ?string $at = null): Repair
    {
        $this->mine($user, $repair, [Repair::ACCEPTED]);
        $repair->forceFill(['status' => Repair::EN_ROUTE, 'departed_at' => RepairClock::at($at)])->save();
        $this->toCourier($repair, 'repair_en_route', "🛠️ {$user->name} est en route", "Le mécanicien vient pour : {$repair->title}.");

        return $repair;
    }

    /** « Je suis arrivé » : heure et position d'arrivée */
    public function arrive(User $user, Repair $repair, ?string $at = null, ?float $lat = null, ?float $lng = null): Repair
    {
        $this->mine($user, $repair, [Repair::ACCEPTED, Repair::EN_ROUTE]);
        $when = RepairClock::at($at);
        $repair->forceFill([
            'status' => Repair::ON_SITE,
            'departed_at' => $repair->departed_at ?? $when,
            'arrived_at' => $when,
            'arrival_lat' => $lat,
            'arrival_lng' => $lng,
        ])->save();

        return $repair;
    }

    /**
     * Diagnostic : type de panne, pièce touchée, décision ; la réparation commence.
     *
     * @param  array<string, mixed>  $data  category, part, diagnosis, decision, at
     */
    public function start(User $user, Repair $repair, array $data): Repair
    {
        $this->mine($user, $repair, [Repair::ON_SITE, Repair::REPAIRING]);
        $category = $this->settings->category($repair->company ?? $user->company, $data['category'] ?? null)
            ?? throw new BusinessRuleException('Choisissez le type de panne.', 'category');
        if (! array_key_exists($data['decision'] ?? '', Repair::DECISIONS)) {
            throw new BusinessRuleException('Indiquez la décision.', 'decision');
        }
        $repair->forceFill([
            'status' => Repair::REPAIRING,
            'started_at' => $repair->started_at ?? RepairClock::at($data['at'] ?? null),
            'category' => $category['key'],
            'category_label' => $category['label'],
            'part' => $this->text($data['part'] ?? null, 120),
            'diagnosis' => $this->text($data['diagnosis'] ?? null, 2000),
            'decision' => $data['decision'],
        ])->save();

        // Remorquage ou véhicule de remplacement : l'équipe s'organise tout de suite
        if ($data['decision'] !== 'fixed') {
            $this->toTeam($repair, 'repair_decision', "🚚 {$repair->title} : ".mb_strtolower(Repair::DECISIONS[$data['decision']]),
                "{$user->name} : ".($repair->part ?: $category['label']).'. '.$this->dispatcher->summary($repair));
        }

        return $repair;
    }

    /**
     * Photos de l'intervention (avant, après, facture), envoyées au fur et à mesure.
     *
     * @param  list<UploadedFile>  $photos
     * @return list<RepairFile>
     */
    public function addPhotos(User $user, Repair $repair, string $kind, array $photos): array
    {
        $this->mine($user, $repair, [Repair::ACCEPTED, Repair::EN_ROUTE, Repair::ON_SITE, Repair::REPAIRING]);
        if (! in_array($kind, RepairFile::KINDS, true)) {
            throw new BusinessRuleException('Type de photo inconnu.', 'kind');
        }

        return array_map(fn (UploadedFile $photo) => $repair->files()->create([
            'kind' => $kind,
            'disk' => 'local',
            'path' => $photo->store("repairs/{$repair->id}", 'local'),
            'mime_type' => $photo->getMimeType(),
        ]), $photos);
    }

    public function deletePhoto(User $user, Repair $repair, RepairFile $file): void
    {
        $this->mine($user, $repair, [Repair::ACCEPTED, Repair::EN_ROUTE, Repair::ON_SITE, Repair::REPAIRING]);
        abort_unless($file->repair_id === $repair->id, 404);
        Storage::disk($file->disk)->delete($file->path);
        $file->delete();
    }

    /**
     * Demande d'achat d'une pièce sur place : l'administrateur (ou qui en a le droit) accorde ou refuse.
     *
     * @param  array<string, mixed>  $data  label, quantity, estimated_amount, reason
     */
    public function requestPurchase(User $user, Repair $repair, array $data): RepairPurchase
    {
        $this->mine($user, $repair, [Repair::ACCEPTED, Repair::EN_ROUTE, Repair::ON_SITE, Repair::REPAIRING]);
        $purchase = $repair->purchases()->create([
            'company_id' => $repair->company_id,
            'mechanic_id' => $repair->mechanic_id,
            'label' => $this->text($data['label'], 120),
            'quantity' => max(1, (int) ($data['quantity'] ?? 1)),
            'estimated_amount' => (int) $data['estimated_amount'],
            'reason' => $this->text($data['reason'] ?? null, 255),
            'status' => RepairPurchase::PENDING,
        ]);

        Notification::send($this->dispatcher->workshopTeam($repair->company_id, Permission::RepairsPurchases), new RepairAlert(
            $repair,
            'repair_purchase',
            "🛒 Achat sur place à accorder : {$purchase->label}",
            "{$user->name} demande à acheter {$purchase->quantity} × {$purchase->label} (environ ".Money::format($purchase->estimated_amount).')'
                .($purchase->reason ? " : {$purchase->reason}" : '').". {$repair->title}.",
            'alert',
        ));

        return $purchase;
    }

    /**
     * Décision sur un achat sur place : accordé avec un montant maximum (par défaut le prix estimé), ou refusé.
     */
    public function decidePurchase(User $actor, RepairPurchase $purchase, bool $approve, ?int $maxAmount = null, ?string $note = null): RepairPurchase
    {
        if ($purchase->status !== RepairPurchase::PENDING) {
            throw new BusinessRuleException('Cette demande a déjà reçu une réponse.');
        }
        $purchase->forceFill([
            'status' => $approve ? RepairPurchase::APPROVED : RepairPurchase::REFUSED,
            'max_amount' => $approve ? ($maxAmount ?: $purchase->estimated_amount) : null,
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'decision_note' => $this->text($note, 255),
        ])->save();

        $purchase->loadMissing(['repair', 'mechanic.user']);
        $purchase->mechanic?->user?->notify(new RepairAlert(
            $purchase->repair,
            'repair_purchase_decided',
            $approve ? "✅ Achat accordé : {$purchase->label}" : "✖️ Achat refusé : {$purchase->label}",
            $approve ? "{$actor->name} accorde jusqu'à ".Money::format($purchase->max_amount).'. Gardez la facture.'.($purchase->decision_note ? " {$purchase->decision_note}" : '')
                : "{$actor->name} : ".($purchase->decision_note ?: 'pas d\'achat sur place.'),
            $approve ? 'note' : 'warning',
        ));

        return $purchase;
    }

    /**
     * Clôture de l'intervention.
     *
     * @param  array<string, mixed>  $data  parts (label, quantity, unit_price), purchases (id => paid_amount),
     *                                      cause, decision, odometer, garage, garage_amount, remark, courier_can_resume, at
     * @param  array<int, UploadedFile>  $receipts  facture de chaque achat sur place (par identifiant)
     */
    public function close(User $user, Repair $repair, array $data, array $receipts = []): Repair
    {
        $this->mine($user, $repair, [Repair::ON_SITE, Repair::REPAIRING]);
        if ($repair->category === null) {
            throw new BusinessRuleException('Faites d\'abord le diagnostic.', 'category');
        }
        if (! array_key_exists($data['cause'] ?? '', Repair::CAUSES)) {
            throw new BusinessRuleException('Indiquez la cause de la panne.', 'cause');
        }
        $decision = array_key_exists($data['decision'] ?? '', Repair::DECISIONS) ? $data['decision'] : $repair->decision;

        $lines = [];
        foreach ($data['parts'] ?? [] as $line) {
            $label = $this->text($line['label'] ?? null, 120);
            if ($label === null) {
                continue;
            }
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $unit = max(0, (int) ($line['unit_price'] ?? 0));
            $lines[] = ['kind' => 'part', 'label' => $label, 'quantity' => $quantity, 'unit_price' => $unit, 'amount' => $quantity * $unit];
        }

        $purchases = $repair->purchases()->get();
        if ($purchases->contains('status', RepairPurchase::PENDING)) {
            throw new BusinessRuleException('Une demande d\'achat attend encore la réponse de l\'administrateur.', 'purchases');
        }
        $paid = $data['purchases'] ?? [];
        foreach ($purchases->where('status', RepairPurchase::APPROVED) as $purchase) {
            $amount = isset($paid[$purchase->id]) && $paid[$purchase->id] !== '' ? (int) $paid[$purchase->id] : null;
            if ($amount === null) {
                continue;
            }
            if ($purchase->max_amount !== null && $amount > $purchase->max_amount) {
                throw new BusinessRuleException("{$purchase->label} : le prix payé dépasse le montant accordé (".Money::format($purchase->max_amount).').', "purchases.{$purchase->id}");
            }
            $lines[] = ['kind' => 'purchase', 'purchase_id' => $purchase->id, 'label' => $purchase->label, 'quantity' => $purchase->quantity,
                'unit_price' => null, 'amount' => $amount];
        }

        $finished = RepairClock::at($data['at'] ?? null);
        $canResume = (bool) ($data['courier_can_resume'] ?? false) && $decision === 'fixed';

        DB::transaction(function () use ($repair, $data, $lines, $paid, $purchases, $receipts, $decision, $finished, $canResume) {
            foreach ($purchases->where('status', RepairPurchase::APPROVED) as $purchase) {
                if (! isset($paid[$purchase->id]) || $paid[$purchase->id] === '') {
                    continue;
                }
                $purchase->forceFill([
                    'paid_amount' => (int) $paid[$purchase->id],
                    'receipt_path' => isset($receipts[$purchase->id]) ? $receipts[$purchase->id]->store("repairs/{$repair->id}", 'local') : $purchase->receipt_path,
                ])->save();
            }
            $repair->forceFill([
                'status' => Repair::DONE,
                'started_at' => $repair->started_at ?? $repair->arrived_at ?? $finished,
                'finished_at' => $finished,
                'decision' => $decision,
                'cause' => $data['cause'],
                'parts' => $lines,
                'parts_amount' => array_sum(array_column($lines, 'amount')),
                'garage' => $decision === 'towed' ? $this->text($data['garage'] ?? null, 120) : null,
                'garage_amount' => $decision === 'towed' && ! empty($data['garage_amount']) ? (int) $data['garage_amount'] : null,
                'odometer' => ! empty($data['odometer']) ? (int) $data['odometer'] : null,
                'remark' => $this->text($data['remark'] ?? null, 2000),
                'courier_can_resume' => $canResume,
            ])->save();
            $this->logToVehicle($repair);
        });

        $this->afterClose($user, $repair->fresh());

        return $repair->fresh();
    }

    /**
     * Fichiers que le mécanicien peut voir : ceux de l'intervention et ceux du signalement du livreur.
     */
    public function canSee(User $user, Repair $repair): bool
    {
        $mechanic = $user->mechanic;

        return $mechanic !== null && $repair->company_id === $user->company_id
            && ($repair->mechanic_id === $mechanic->id || $repair->status === Repair::SEARCHING);
    }

    /** Ligne « Réparation » du carnet : celle posée au signalement (sans montant), sinon une nouvelle */
    private function logToVehicle(Repair $repair): void
    {
        $vehicle = $repair->vehicle_id ? Vehicle::withoutGlobalScopes()->find($repair->vehicle_id) : null;
        if ($vehicle === null) {
            return;
        }
        $label = trim('Réparation '.mb_strtolower((string) $repair->category_label).($repair->part ? " : {$repair->part}" : '')
            .' · '.Repair::DECISIONS[$repair->decision ?? 'fixed']);
        $photos = $repair->files()->where('kind', 'after')->pluck('path')->all();
        $existing = $repair->field_event_id
            ? VehicleLog::withoutGlobalScopes()->where('field_event_id', $repair->field_event_id)->where('kind', 'repair')
                ->where('paid_by', 'company')->where('amount', 0)->first()
            : null;

        if ($existing) {
            $existing->forceFill([
                'label' => $label,
                'amount' => $repair->total(),
                'odometer' => $repair->odometer ?? $existing->odometer,
                'garage' => $repair->garage,
                'photos' => [...($existing->photos ?? []), ...$photos],
            ])->save();
            $vehicle->readOdometer($repair->odometer);
            $vehicle->save();
            $log = $existing;
        } else {
            $log = $this->logs->add(null, $vehicle, [
                'kind' => 'repair',
                'done_on' => $repair->finished_at->toDateString(),
                'courier_id' => $repair->courier_id,
                'label' => $label,
                'amount' => $repair->total(),
                'odometer' => $repair->odometer,
                'garage' => $repair->garage,
                'paid_by' => 'company',
                'field_event_id' => $repair->field_event_id,
            ]);
            if ($photos !== []) {
                $log->forceFill(['photos' => $photos])->save();
            }
        }
        $repair->forceFill(['vehicle_log_id' => $log->id])->save();
    }

    /** Livreur remis en service si le véhicule est réparé ; sinon l'équipe organise la suite */
    private function afterClose(User $user, Repair $repair): void
    {
        $event = $repair->field_event_id ? FieldEvent::withoutGlobalScopes()->find($repair->field_event_id) : null;
        $what = ($repair->category_label ?? $repair->title).($repair->part ? " ({$repair->part})" : '');

        if ($repair->courier_can_resume) {
            if ($event && ! $event->isResolved()) {
                $this->fieldEvents->resolve($user, $event, "Réparé par {$user->name} : {$what}. Vous pouvez repartir.");
            } elseif ($repair->vehicle_id) {
                $vehicle = Vehicle::withoutGlobalScopes()->find($repair->vehicle_id);
                if ($vehicle && $vehicle->status === 'broken') {
                    $this->fleet->setStatus($vehicle, 'active');
                }
            }

            return;
        }

        $decision = Repair::DECISIONS[$repair->decision ?? 'fixed'];
        if ($event && ! $event->isResolved()) {
            $this->fieldEvents->message($user, $event, "Intervention terminée : {$what}. {$decision}. L'agence vous dit comment continuer.");
        }
        $this->toTeam($repair, 'repair_done_blocked', "🛠️ Intervention terminée, livreur pas encore reparti : {$repair->title}",
            "{$user->name} : {$what}. {$decision}. ".$this->dispatcher->summary($repair));
    }

    /**
     * L'intervention est au mécanicien et à une étape attendue.
     *
     * @param  list<string>  $statuses
     */
    private function mine(User $user, Repair $repair, array $statuses): void
    {
        $mechanic = $this->dispatcher->mechanicOf($user);
        if ($repair->mechanic_id !== $mechanic->id || ! in_array($repair->status, [...Repair::IN_PROGRESS, Repair::OFFERED], true)) {
            throw new BusinessRuleException($repair->status === Repair::CANCELLED ? 'Cette intervention a été annulée.' : 'Cette intervention ne vous est pas confiée.');
        }
        if (! in_array($repair->status, $statuses, true)) {
            throw new BusinessRuleException(match ($repair->status) {
                Repair::OFFERED => 'Acceptez d\'abord la panne.',
                Repair::DONE => 'Cette intervention est terminée.',
                default => 'Cette étape est déjà passée.',
            });
        }
    }

    private function toCourier(Repair $repair, string $kind, string $title, string $body): void
    {
        $event = $repair->field_event_id ? FieldEvent::withoutGlobalScopes()->find($repair->field_event_id) : null;
        if ($event) {
            $this->fieldAlerts->toCourier($event, $kind, $title, $body);
        }
    }

    private function toTeam(Repair $repair, string $kind, string $title, string $body): void
    {
        Notification::send($this->dispatcher->workshopTeam($repair->company_id), new RepairAlert($repair, $kind, $title, $body, 'warning'));
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
