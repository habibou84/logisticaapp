<?php

namespace App\Services\FieldEvents;

use App\Enums\AssignmentStatus;
use App\Enums\OrderEventType;
use App\Exceptions\BusinessRuleException;
use App\Models\Courier;
use App\Models\FieldEvent;
use App\Models\FieldEventMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderJournal;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Événements terrain : le livreur signale (panne, accident, contrôle…) avec les courses touchées, une photo,
 * une note vocale et s'il peut continuer ; l'équipe est alertée, prend en charge, échange avec lui, résout.
 * Le livreur dit « C'est réglé » pour reprendre. Les effets automatiques (pause, courses remises, planning,
 * clients prévenus) sont dans FieldEventEffects, au choix de l'administrateur.
 */
class FieldEvents
{
    // Heure du téléphone acceptée pour un signalement envoyé plus tard (hors connexion)
    private const MAX_AGE_HOURS = 72;

    public function __construct(
        private readonly FieldEventSettings $settings,
        private readonly FieldEventEffects $effects,
        private readonly FieldEventAlerts $alerts,
        private readonly OrderJournal $journal,
    ) {}

    /**
     * Signalement d'un livreur. Renvoyé deux fois (même identifiant du téléphone) : créé une seule fois.
     *
     * @param  array<string, mixed>  $data  type, capacity, blocked_minutes, note, lat, lng, cost, order_ids, client_uuid, occurred_at, voice_duration
     * @param  list<UploadedFile>  $photos
     */
    public function report(User $user, array $data, array $photos = [], ?UploadedFile $voice = null): FieldEvent
    {
        $courier = $user->courier ?? throw new BusinessRuleException('Profil livreur introuvable.');
        $company = $courier->company ?? $user->company;

        if (! empty($data['client_uuid'])) {
            $existing = FieldEvent::where('courier_id', $courier->id)->where('client_uuid', $data['client_uuid'])->first();
            if ($existing) {
                return $existing;
            }
        }

        $settings = $this->settings->for($company);
        if (! $settings['enabled']) {
            throw new BusinessRuleException('Le signalement des événements terrain n\'est pas activé.');
        }
        $type = $this->settings->type($company, (string) $data['type']);
        if ($type === null || ! $type['active']) {
            throw new BusinessRuleException('Ce type d\'événement n\'est pas proposé.', 'type');
        }
        if ($type['photo'] && $photos === []) {
            throw new BusinessRuleException("Une photo est demandée pour « {$type['label']} ».", 'photos');
        }
        if ($voice !== null && ! $settings['voice_notes']) {
            $voice = null;
        }

        $tz = $company->timezone ?: config('app.timezone');
        $occurredAt = $this->occurredAt($data['occurred_at'] ?? null);
        $capacity = in_array($data['capacity'] ?? null, FieldEvent::CAPACITIES, true) ? $data['capacity'] : 'continue';
        $blockedUntil = match ($capacity) {
            'blocked' => ! empty($data['blocked_minutes']) ? $occurredAt->addMinutes((int) $data['blocked_minutes']) : null,
            'stopped' => $occurredAt->setTimezone($tz)->endOfDay(),
            default => null,
        };

        // Courses touchées : seulement celles que le livreur a en main
        $mine = $this->activeOrderIds($courier);
        $orderIds = array_key_exists('order_ids', $data) && is_array($data['order_ids'])
            ? array_values(array_intersect(array_map('intval', $data['order_ids']), $mine))
            : $mine;

        $event = DB::transaction(function () use ($courier, $data, $type, $settings, $capacity, $blockedUntil, $occurredAt, $orderIds, $photos, $voice) {
            $event = FieldEvent::create([
                'company_id' => $courier->company_id,
                'courier_id' => $courier->id,
                'client_uuid' => $data['client_uuid'] ?? null,
                'type' => $type['key'],
                'type_label' => $type['label'],
                'icon' => $type['icon'],
                'severity' => $type['severity'],
                'capacity' => $capacity,
                'blocked_until' => $blockedUntil,
                'note' => isset($data['note']) ? mb_substr(trim((string) $data['note']), 0, 1000) ?: null : null,
                'lat' => $type['gps'] ? ($data['lat'] ?? null) : null,
                'lng' => $type['gps'] ? ($data['lng'] ?? null) : null,
                'cost' => $settings['courier_cost'] && ! empty($data['cost']) ? (int) $data['cost'] : null,
                'status' => FieldEvent::OPEN,
                'excused' => $settings['excuse_delays'] && $type['excuse'],
                'created_at' => $occurredAt,
            ]);
            $event->orders()->sync($orderIds);

            foreach ($photos as $photo) {
                $this->storeFile($event, 'photo', $photo);
            }
            if ($voice !== null) {
                $this->storeFile($event, 'voice', $voice, isset($data['voice_duration']) ? min((int) $data['voice_duration'], 600) : null);
            }

            // Noté dans l'historique des courses touchées (note interne)
            $courier->loadMissing('user');
            foreach (Order::whereIn('id', $orderIds)->get() as $order) {
                $this->journal->record($order, null, OrderEventType::Note, [
                    'note' => "{$type['icon']} Événement terrain signalé par {$courier->user?->name} : {$type['label']}".($event->note ? " · « {$event->note} »" : ''),
                    'visible_to_merchant' => false,
                    'meta' => ['field_event_id' => $event->id],
                ]);
            }

            return $event;
        });

        DB::afterCommit(function () use ($event) {
            $this->effects->onReported($event);
            $this->alerts->reported($event->fresh());
        });

        return $event;
    }

    /**
     * L'équipe prend l'événement en charge : le livreur voit qui s'en occupe.
     */
    public function take(User $actor, FieldEvent $event): FieldEvent
    {
        if ($event->isResolved()) {
            throw new BusinessRuleException('Cet événement est déjà résolu.');
        }
        $event->forceFill(['status' => FieldEvent::TAKEN, 'taken_by' => $actor->id, 'taken_at' => now()])->save();
        $this->alerts->toCourier($event, 'field_event_taken', "👍 {$actor->name} s'occupe de votre signalement", "{$event->type_label} : l'agence est au courant.");

        return $event;
    }

    public function resolve(User $actor, FieldEvent $event, ?string $resolution = null): FieldEvent
    {
        if ($event->isResolved()) {
            return $event;
        }
        $event->forceFill([
            'status' => FieldEvent::RESOLVED,
            'taken_by' => $event->taken_by ?? $actor->id,
            'taken_at' => $event->taken_at ?? now(),
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
            'resolution' => $resolution ? mb_substr(trim($resolution), 0, 1000) : null,
        ])->save();
        $this->effects->onResolved($event);
        $this->alerts->toCourier($event, 'field_event_resolved', "✅ Signalement clos par {$actor->name}", $event->resolution ?: "{$event->type_label} : réglé.");

        return $event;
    }

    public function reopen(FieldEvent $event): FieldEvent
    {
        $event->forceFill([
            'status' => $event->taken_by ? FieldEvent::TAKEN : FieldEvent::OPEN,
            'resolved_by' => null,
            'resolved_at' => null,
            'resolution' => null,
            'resumed_at' => null,
        ])->save();

        return $event;
    }

    /**
     * « C'est réglé, je reprends » : l'événement est clos et le livreur reprend son service.
     */
    public function resume(User $user, FieldEvent $event): FieldEvent
    {
        if (! $event->isResolved()) {
            $event->forceFill([
                'status' => FieldEvent::RESOLVED,
                'resolved_by' => $user->id,
                'resolved_at' => now(),
                'resolution' => 'Le livreur a repris.',
            ])->save();
        }
        $event->forceFill(['resumed_at' => $event->resumed_at ?? now()])->save();
        $this->effects->onResolved($event, true);
        $this->alerts->toStaff($event, 'field_event_resumed', "✅ {$user->name} a repris", "{$event->type_label} : réglé, de nouveau en service.", 'note');

        return $event;
    }

    /**
     * Message du livreur ou de l'équipe sur l'événement ; l'autre côté est prévenu.
     */
    public function message(User $sender, FieldEvent $event, string $body): FieldEventMessage
    {
        $body = trim($body);
        if ($body === '') {
            throw new BusinessRuleException('Écrivez votre message.', 'body');
        }
        $fromCourier = $sender->isCourier();
        $message = $event->messages()->create([
            'company_id' => $event->company_id,
            'sender_id' => $sender->id,
            'from_courier' => $fromCourier,
            'body' => mb_substr($body, 0, 1000),
        ]);

        if ($fromCourier) {
            $this->alerts->toStaff($event, 'field_event_message', "💬 {$sender->name} · {$event->type_label}", $message->body, 'note');
        } else {
            // Répondre au livreur vaut prise en charge
            if ($event->status === FieldEvent::OPEN) {
                $event->forceFill(['status' => FieldEvent::TAKEN, 'taken_by' => $sender->id, 'taken_at' => now()])->save();
            }
            $this->alerts->toCourier($event, 'field_event_message', "💬 {$sender->name}", $message->body);
        }

        return $message->load('sender');
    }

    /**
     * Courses que le livreur a en main (missions en cours).
     *
     * @return list<int>
     */
    public function activeOrderIds(Courier $courier): array
    {
        return $courier->assignments()->whereIn('status', AssignmentStatus::activeValues())
            ->pluck('order_id')->unique()->map(fn ($id) => (int) $id)->values()->all();
    }

    private function storeFile(FieldEvent $event, string $kind, UploadedFile $file, ?int $duration = null): void
    {
        $event->files()->create([
            'kind' => $kind,
            'disk' => 'local',
            'path' => $file->store("field-events/{$event->id}", 'local'),
            'mime_type' => $file->getMimeType(),
            'duration' => $duration,
        ]);
    }

    private function occurredAt(?string $value): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        try {
            $at = $value ? CarbonImmutable::parse($value) : $now;
        } catch (\Throwable) {
            return $now;
        }

        return $at->lt($now->subHours(self::MAX_AGE_HOURS)) || $at->gt($now->addMinutes(5)) ? $now : $at;
    }
}
