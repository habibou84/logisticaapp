<?php

namespace App\Services\Workshop;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\FieldEvent;
use App\Models\Mechanic;
use App\Models\Repair;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\RepairAlert;
use App\Services\FieldEvents\FieldEvents;
use App\Services\Vehicles\Fleet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Attribution des pannes aux mécaniciens. Une panne signalée par un livreur devient une intervention, proposée
 * automatiquement au mécanicien en service le plus proche (puis au moins chargé). Sans réponse dans le délai réglé,
 * ou s'il refuse, elle passe au suivant. Personne en service ou tout le monde a refusé : l'équipe qui suit l'atelier
 * est prévenue et attribue à la main. Un mécanicien en service peut aussi prendre lui-même une panne sans mécanicien.
 */
class RepairDispatcher
{
    public function __construct(
        private readonly WorkshopSettings $settings,
        private readonly Fleet $fleet,
    ) {}

    /**
     * Événement terrain signalé : intervention créée (une seule par événement) pour les types qui demandent un
     * mécanicien, puis proposée. Sans mécanicien dans l'entreprise : rien (l'équipe suit l'événement comme avant).
     */
    public function fromFieldEvent(FieldEvent $event): ?Repair
    {
        $event->loadMissing(['company', 'courier']);
        $settings = $this->settings->for($event->company);
        if (! in_array($event->type, $settings['event_types'], true) || $event->isResolved()) {
            return null;
        }
        if (! Mechanic::withoutGlobalScopes()->where('company_id', $event->company_id)->exists()) {
            return null;
        }
        $existing = Repair::withoutGlobalScopes()->where('field_event_id', $event->id)->first();
        if ($existing) {
            return $existing;
        }

        $repair = Repair::create([
            'company_id' => $event->company_id,
            'field_event_id' => $event->id,
            'vehicle_id' => $event->courier ? $this->fleet->currentFor($event->courier)?->id : null,
            'courier_id' => $event->courier_id,
            'source' => 'field_event',
            'title' => $event->type_label,
            'icon' => $event->icon,
            'severity' => $event->severity,
            'note' => $event->note,
            'lat' => $event->lat ?? $event->courier?->current_lat,
            'lng' => $event->lng ?? $event->courier?->current_lng,
            'status' => Repair::SEARCHING,
        ]);
        $repair->setRelation('company', $event->company);

        return $settings['auto_assign'] ? $this->offer($repair) : $this->escalate($repair);
    }

    /**
     * Intervention créée par l'équipe (problème noté au contrôle de départ, entretien urgent…).
     *
     * @param  array<string, mixed>  $data  vehicle_id, title, note, severity, lat, lng, mechanic_id
     */
    public function create(User $actor, array $data): Repair
    {
        $vehicle = isset($data['vehicle_id']) ? Vehicle::query()->find($data['vehicle_id']) : null;
        $repair = Repair::create([
            'company_id' => $actor->company_id,
            'vehicle_id' => $vehicle?->id,
            'courier_id' => $vehicle?->courier_id,
            'source' => 'manual',
            'title' => trim((string) $data['title']),
            'icon' => '🛠️',
            'severity' => $data['severity'] ?? 'blocking',
            'note' => isset($data['note']) ? trim((string) $data['note']) ?: null : null,
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'status' => Repair::SEARCHING,
            'created_by' => $actor->id,
        ]);

        if (! empty($data['mechanic_id'])) {
            return $this->assign($actor, $repair, Mechanic::query()->findOrFail($data['mechanic_id']));
        }

        return $this->settings->for($actor->company)['auto_assign'] ? $this->offer($repair) : $this->escalate($repair);
    }

    /**
     * Propose l'intervention au meilleur mécanicien disponible ; sans personne, l'équipe est prévenue (une fois).
     */
    public function offer(Repair $repair): Repair
    {
        $repair->loadMissing('company');
        $mechanic = $this->candidates($repair)->first();
        if ($mechanic === null) {
            return $this->escalate($repair);
        }

        $minutes = (int) $this->settings->for($repair->company)['offer_minutes'];
        $repair->forceFill([
            'status' => Repair::OFFERED,
            'mechanic_id' => $mechanic->id,
            'offered_at' => now(),
            'offer_expires_at' => now()->addMinutes($minutes),
        ])->save();

        $mechanic->user?->notify(new RepairAlert(
            $repair,
            'repair_offered',
            "🚨 Nouvelle panne : {$repair->title}",
            $this->summary($repair)." Répondez dans les {$minutes} min.",
            'alert',
        ));

        return $repair;
    }

    /**
     * Mécaniciens à qui proposer : en service, compte actif, pas déjà sollicités pour une autre panne, sans
     * intervention urgente en cours, et qui n'ont pas déjà refusé celle-ci. Le plus proche d'abord (position connue),
     * puis le moins chargé.
     *
     * @return Collection<int, Mechanic>
     */
    public function candidates(Repair $repair): Collection
    {
        $busy = Repair::withoutGlobalScopes()->where('company_id', $repair->company_id)->whereKeyNot($repair->id)
            ->where(fn ($q) => $q->where('status', Repair::OFFERED)
                ->orWhere(fn ($q) => $q->whereIn('status', Repair::IN_PROGRESS)->where('severity', 'urgent')))
            ->whereNotNull('mechanic_id')->pluck('mechanic_id')->all();

        return Mechanic::withoutGlobalScopes()->with('user')->withCount('activeRepairs')
            ->where('company_id', $repair->company_id)
            ->where('is_available', true)
            ->whereNotIn('id', [...$busy, ...$repair->declinedIds()])
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->get()
            ->filter(fn (Mechanic $m) => $m->user?->isMechanic())
            ->map(function (Mechanic $m) use ($repair) {
                $m->distance_km = $repair->lat !== null && $m->current_lat !== null
                    ? round(self::km($repair->lat, $repair->lng, $m->current_lat, $m->current_lng), 1) : null;

                return $m;
            })
            ->sortBy(fn (Mechanic $m) => [$m->distance_km === null, $m->distance_km ?? 0, $m->active_repairs_count, $m->id])
            ->values();
    }

    /**
     * Le mécanicien accepte : la panne qui lui est proposée, ou une panne sans mécanicien qu'il prend lui-même.
     */
    public function accept(User $user, Repair $repair, ?string $at = null): Repair
    {
        $mechanic = $this->mechanicOf($user);

        DB::transaction(function () use ($mechanic, $repair, $at) {
            $locked = Repair::query()->lockForUpdate()->findOrFail($repair->id);
            $offeredToMe = $locked->status === Repair::OFFERED && $locked->mechanic_id === $mechanic->id;
            if (! $offeredToMe && $locked->status !== Repair::SEARCHING) {
                throw new BusinessRuleException($locked->mechanic_id === $mechanic->id && $locked->isInProgress()
                    ? 'Vous avez déjà accepté cette panne.'
                    : 'Cette panne a déjà été confiée à un autre mécanicien.');
            }
            $locked->forceFill([
                'status' => Repair::ACCEPTED,
                'mechanic_id' => $mechanic->id,
                'accepted_at' => RepairClock::at($at),
                'offer_expires_at' => null,
            ])->save();
        });
        $repair->refresh();
        $this->takeEvent($user, $repair);

        return $repair;
    }

    /**
     * Le mécanicien ne peut pas : la panne passe au suivant.
     */
    public function decline(User $user, Repair $repair): Repair
    {
        $mechanic = $this->mechanicOf($user);
        if ($repair->status !== Repair::OFFERED || $repair->mechanic_id !== $mechanic->id) {
            throw new BusinessRuleException('Cette panne ne vous est plus proposée.');
        }
        $this->release($repair, $mechanic->id);

        return $this->offer($repair);
    }

    /**
     * Propositions restées sans réponse : passées au suivant. Pannes sans mécanicien : de nouveau proposées (un
     * mécanicien a pu se mettre en service). Lancé chaque minute.
     *
     * @return array{expired: int, offered: int}
     */
    public function tick(): array
    {
        $result = ['expired' => 0, 'offered' => 0];

        Repair::withoutGlobalScopes()->with('company')->where('status', Repair::OFFERED)->where('offer_expires_at', '<=', now())
            ->get()->each(function (Repair $repair) use (&$result) {
                $mechanic = Mechanic::withoutGlobalScopes()->with('user')->find($repair->mechanic_id);
                $this->release($repair, $repair->mechanic_id);
                $mechanic?->user?->notify(new RepairAlert($repair, 'repair_expired', '⏱️ Panne passée à un autre mécanicien',
                    "{$repair->title} : sans réponse de votre part, elle a été proposée au suivant.", 'note'));
                $this->offer($repair);
                $result['expired']++;
            });

        $result['offered'] = $this->retrySearching();

        return $result;
    }

    /**
     * Pannes sans mécanicien (attribution automatique) : proposées à qui est disponible.
     */
    public function retrySearching(?int $companyId = null): int
    {
        $count = 0;
        Repair::withoutGlobalScopes()->with('company')->where('status', Repair::SEARCHING)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->orderByRaw("CASE severity WHEN 'urgent' THEN 0 WHEN 'blocking' THEN 1 ELSE 2 END")->orderBy('id')
            ->get()->each(function (Repair $repair) use (&$count) {
                if (! $this->settings->for($repair->company)['auto_assign']) {
                    return;
                }
                if ($this->offer($repair)->status === Repair::OFFERED) {
                    $count++;
                }
            });

        return $count;
    }

    /**
     * L'équipe confie la panne à un mécanicien : elle est à lui tout de suite (pas de délai pour accepter).
     */
    public function assign(User $actor, Repair $repair, Mechanic $mechanic): Repair
    {
        if (! $repair->isOpen()) {
            throw new BusinessRuleException('Cette intervention est terminée.');
        }
        if ($repair->isInProgress() && $repair->mechanic_id === $mechanic->id) {
            return $repair;
        }
        if ($repair->status !== Repair::SEARCHING && $repair->status !== Repair::OFFERED && $repair->status !== Repair::ACCEPTED) {
            throw new BusinessRuleException('Le mécanicien est déjà en route ou sur place : impossible de changer.');
        }
        $previous = $repair->mechanic_id && $repair->mechanic_id !== $mechanic->id
            ? Mechanic::withoutGlobalScopes()->with('user')->find($repair->mechanic_id) : null;

        $repair->forceFill([
            'status' => Repair::ACCEPTED,
            'mechanic_id' => $mechanic->id,
            'offered_at' => $repair->offered_at ?? now(),
            'offer_expires_at' => null,
            'accepted_at' => now(),
        ])->save();

        $previous?->user?->notify(new RepairAlert($repair, 'repair_reassigned', '↪️ Panne confiée à un autre mécanicien',
            "{$repair->title} : {$actor->name} l'a confiée à {$mechanic->user?->name}.", 'note'));
        $mechanic->loadMissing('user');
        $mechanic->user?->notify(new RepairAlert($repair, 'repair_assigned', "🛠️ Panne confiée par {$actor->name} : {$repair->title}",
            $this->summary($repair), 'alert'));
        $this->takeEvent($mechanic->user, $repair);

        return $repair;
    }

    /**
     * Intervention annulée (par l'équipe, ou parce que le livreur a repris avant l'arrivée du mécanicien).
     */
    public function cancel(?User $actor, Repair $repair, ?string $reason = null): Repair
    {
        if (! $repair->isOpen()) {
            return $repair;
        }
        $mechanic = $repair->mechanic_id ? Mechanic::withoutGlobalScopes()->with('user')->find($repair->mechanic_id) : null;
        $wasOn = $mechanic && ($repair->status === Repair::OFFERED || $repair->isInProgress());
        $repair->forceFill(['status' => Repair::CANCELLED, 'cancelled_at' => now(), 'offer_expires_at' => null])->save();

        if ($wasOn) {
            $mechanic->user?->notify(new RepairAlert($repair, 'repair_cancelled', "✖️ Panne annulée : {$repair->title}",
                $reason ?: ($actor ? "Annulée par {$actor->name}." : 'Plus besoin d\'intervenir.'), 'note'));
        }

        return $repair;
    }

    /**
     * Événement terrain réglé sans le mécanicien (le livreur a repris, l'équipe l'a clos) : l'intervention qui
     * n'avait pas commencé est annulée.
     */
    public function eventResolved(FieldEvent $event): void
    {
        Repair::withoutGlobalScopes()->where('field_event_id', $event->id)->open()
            ->whereNotIn('status', [Repair::ON_SITE, Repair::REPAIRING])
            ->get()->each(fn (Repair $r) => $this->cancel(null, $r, 'Le livreur a repris : plus besoin d\'intervenir.'));
    }

    /** Résumé d'une panne pour les alertes */
    public function summary(Repair $repair): string
    {
        $repair->loadMissing(['courier.user', 'vehicle']);
        $parts = array_filter([
            $repair->courier?->user?->name,
            $repair->vehicle?->plate,
            $repair->note ? '« '.mb_strimwidth($repair->note, 0, 100, '…').' »' : null,
        ]);

        return $parts ? implode(' · ', $parts).'.' : '';
    }

    public function mechanicOf(User $user): Mechanic
    {
        $user->syncMechanicProfile();

        return $user->mechanic ?? throw new BusinessRuleException('Profil mécanicien introuvable.');
    }

    /** La proposition est retirée au mécanicien (refus ou délai passé) : il ne la reverra plus */
    private function release(Repair $repair, ?int $mechanicId): void
    {
        $repair->forceFill([
            'status' => Repair::SEARCHING,
            'mechanic_id' => null,
            'offer_expires_at' => null,
            'declined' => array_values(array_unique([...$repair->declinedIds(), ...($mechanicId ? [$mechanicId] : [])])),
        ])->save();
    }

    /** Personne pour la panne : l'équipe de l'atelier est prévenue (une seule fois) */
    private function escalate(Repair $repair): Repair
    {
        if ($repair->status !== Repair::SEARCHING) {
            $repair->forceFill(['status' => Repair::SEARCHING, 'mechanic_id' => null, 'offer_expires_at' => null])->save();
        }
        if ($repair->escalated_at !== null) {
            return $repair;
        }
        $repair->forceFill(['escalated_at' => now()])->save();
        $anyone = Mechanic::withoutGlobalScopes()->where('company_id', $repair->company_id)->where('is_available', true)->exists();

        Notification::send($this->workshopTeam($repair->company_id), new RepairAlert(
            $repair,
            'repair_unassigned',
            "⚠️ Panne sans mécanicien : {$repair->title}",
            ($anyone ? 'Aucun mécanicien en service n\'a accepté. ' : 'Aucun mécanicien en service. ').$this->summary($repair).' Confiez-la depuis l\'Atelier.',
            'alert',
        ));

        return $repair;
    }

    /**
     * Comptes de l'équipe qui suivent l'atelier.
     *
     * @return Collection<int, User>
     */
    public function workshopTeam(int $companyId, Permission $permission = Permission::RepairsManage): Collection
    {
        return User::forCompany($companyId)->where('status', 'active')->whereNull('merchant_id')->get()
            ->filter(fn (User $u) => ! $u->isCourier() && ! $u->isMechanic() && $u->can($permission->value))
            ->values();
    }

    /** L'événement terrain est pris en charge par le mécanicien : le livreur voit qui vient */
    private function takeEvent(?User $mechanicUser, Repair $repair): void
    {
        if ($mechanicUser === null || $repair->field_event_id === null) {
            return;
        }
        $event = FieldEvent::withoutGlobalScopes()->find($repair->field_event_id);
        if ($event && ! $event->isResolved()) {
            app(FieldEvents::class)->take($mechanicUser, $event);
        }
    }

    private static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $rad = M_PI / 180;
        $a = sin(($lat2 - $lat1) * $rad / 2) ** 2 + cos($lat1 * $rad) * cos($lat2 * $rad) * sin(($lng2 - $lng1) * $rad / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
