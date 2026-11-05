<?php

namespace App\Models;

use App\Enums\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne de l'historique d'un compte (qui a fait quoi, quand). Écrite seulement, jamais modifiée.
 */
class UserAccountEvent extends Model
{
    public const UPDATED_AT = null;

    public const CREATED = 'created';

    public const ROLE_CHANGED = 'role_changed';

    public const PERIMETER_CHANGED = 'perimeter_changed';

    public const RIGHTS_CHANGED = 'rights_changed';

    public const SUSPENDED = 'suspended';

    public const REACTIVATED = 'reactivated';

    public const RENAMED = 'renamed';

    public const CREDENTIALS_CHANGED = 'credentials_changed';

    public const INVITATION_SENT = 'invitation_sent';

    public const INVITATION_USED = 'invitation_used';

    public const LOGGED_OUT = 'logged_out';

    public const SEEN_AS = 'seen_as';

    protected $fillable = ['company_id', 'user_id', 'actor_id', 'action', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withoutGlobalScopes();
    }

    /** @param  array<string, mixed>  $data */
    public static function record(User $user, ?User $actor, string $action, array $data = []): self
    {
        return self::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'actor_id' => $actor?->id,
            'action' => $action,
            'data' => $data ?: null,
        ]);
    }

    /** Phrase lisible : « Rôle changé : Caissier → Dispatcher » */
    public function text(): string
    {
        $d = $this->data ?? [];
        $list = fn (array $items) => implode(', ', $items) ?: 'aucun';

        return match ($this->action) {
            self::CREATED => 'Compte créé'.(isset($d['role']) ? " ({$d['role']})" : ''),
            self::ROLE_CHANGED => 'Rôle changé : '.($d['from'] ?? '—').' → '.($d['to'] ?? '—'),
            self::PERIMETER_CHANGED => 'Périmètre changé : '.$list($d['to'] ?? []).(isset($d['from']) ? ' (avant : '.$list($d['from']).')' : ''),
            self::RIGHTS_CHANGED => 'Droits ajustés'.(($d['added'] ?? []) ? ' : + '.implode(', + ', $d['added']) : '').(($d['removed'] ?? []) ? ' ; − '.implode(', − ', $d['removed']) : ''),
            self::SUSPENDED => 'Compte suspendu',
            self::REACTIVATED => 'Compte réactivé',
            self::RENAMED => 'Nom changé : '.($d['from'] ?? '—').' → '.($d['to'] ?? '—'),
            self::CREDENTIALS_CHANGED => 'Accès modifiés : '.implode(', ', array_map(fn ($f) => ['phone' => 'téléphone', 'email' => 'e-mail', 'password' => 'mot de passe'][$f] ?? $f, $d['fields'] ?? [])),
            self::INVITATION_SENT => 'Lien de connexion préparé (WhatsApp)',
            self::INVITATION_USED => 'Lien de connexion ouvert : compte connecté',
            self::LOGGED_OUT => 'Déconnecté de tous ses appareils',
            self::SEEN_AS => '« Voir comme ce compte » utilisé',
            default => $this->action,
        };
    }

    /** Libellés d'un périmètre (activités, zones, dépôts) pour l'historique */
    public static function perimeter(?array $activities, ?array $zoneIds, ?array $hubIds): array
    {
        return [
            ...array_map(fn ($a) => Activity::tryFrom($a)?->label() ?? $a, $activities ?? []),
            ...Zone::withoutGlobalScopes()->whereIn('id', $zoneIds ?? [])->pluck('name')->all(),
            ...Hub::withoutGlobalScopes()->whereIn('id', $hubIds ?? [])->pluck('name')->all(),
        ];
    }
}
