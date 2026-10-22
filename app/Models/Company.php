<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\PlatformFeature;
use App\Services\Connect\Directory;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'phone',
        'email',
        'address',
        'tagline',
        'currency',
        'timezone',
        'auto_confirm_orders',
        'validation_mode',
        'default_max_attempts',
        'require_delivery_code',
        'offline_courier_actions',
        'return_fee_percent',
        'notify_recipients',
        'sms_fallback',
        'merchant_push_first',
        'whatsapp_orders',
        'merchant_signup',
        'appearance',
        'weekly_report',
        'field_alert_reminder_minutes',
        'parcel_hold_alert_hours',
        'pickup_mission_custody',
        'depot_closes_at',
        'pickup_assign_alert_minutes',
        'daily_cutoff_time',
        'delivery_assign_alert_minutes',
        // Ramassages confiés selon le planning des livreurs : off, trial (essai), on
        'auto_pickup',
        // Acceptation des missions : choose (le livreur accepte ou refuse), report (d'office, empêchement possible), auto
        'mission_acceptance',
        'unreachable_minutes',
        'late_pickup_alert_minutes',
        // Alerte « livreur libre avec des ramassages près de lui »
        'courier_available_alert',
        // Annuaire des entreprises de livraison (jibiat.com/annuaire, Jibiat Marchand)
        'directory_listed',
        'directory_pitch',
        // Livraison du jour même confiée automatiquement : off, trial (essai), on
        'auto_delivery',
        'auto_delivery_max_cod',
        'same_courier_delivery',
        // Heure limite différente certains jours : {"6": "12:00"} (1 = lundi … 7 = dimanche)
        'cutoff_by_day',
        'status',
        'plan_id',
        'trial_ends_at',
    ];

    /** Empreinte de la clé de reprise : jamais renvoyée */
    protected $hidden = ['legacy_import_key_hash'];

    protected function casts(): array
    {
        return [
            'auto_confirm_orders' => 'boolean',
            'default_max_attempts' => 'integer',
            'require_delivery_code' => 'boolean',
            'offline_courier_actions' => 'boolean',
            'return_fee_percent' => 'integer',
            'notify_recipients' => 'boolean',
            'sms_fallback' => 'boolean',
            'merchant_push_first' => 'boolean',
            'whatsapp_orders' => 'boolean',
            'merchant_signup' => 'boolean',
            'appearance' => 'array',
            'weekly_report' => 'boolean',
            'weekly_report_sent_at' => 'datetime',
            'custom_domain_checked_at' => 'datetime',
            'field_alert_reminder_minutes' => 'integer',
            'parcel_hold_alert_hours' => 'integer',
            'pickup_assign_alert_minutes' => 'integer',
            'cutoff_alerted_on' => 'date:Y-m-d',
            'deliveries_closed_on' => 'date:Y-m-d',
            'deliveries_closed_at' => 'datetime',
            'delivery_assign_alert_minutes' => 'integer',
            'planning_digest_sent_on' => 'date:Y-m-d',
            'unreachable_minutes' => 'integer',
            'late_pickup_alert_minutes' => 'integer',
            'courier_available_alert' => 'boolean',
            'directory_listed' => 'boolean',
            'automation_paused_at' => 'datetime',
            'setup_skipped' => 'array',
            'setup_test_delivered_at' => 'datetime',
            'auto_delivery_max_cod' => 'integer',
            'same_courier_delivery' => 'boolean',
            'status' => CompanyStatus::class,
            'trial_ends_at' => 'date:Y-m-d',
            'legacy_import_key_expires_at' => 'datetime',
            'legacy_import_used_at' => 'datetime',
        ];
    }

    /** Modes de validation des courses */
    /** Après une mission ramassage : le livreur garde les colis, les rapporte au dépôt, ou au choix du dispatch */
    public const CUSTODY_KEEP = 'keep';

    public const CUSTODY_DEPOT = 'depot';

    public const CUSTODY_CHOOSE = 'choose';

    /** Assignation automatique : désactivée, à l'essai (le choix est noté, rien n'est envoyé), active */
    public const AUTO_OFF = 'off';

    public const AUTO_TRIAL = 'trial';

    public const AUTO_ON = 'on';

    /** Acceptation des missions : le livreur accepte ou refuse, acceptées d'office (empêchement possible), acceptées d'office */
    public const ACCEPT_CHOOSE = 'choose';

    public const ACCEPT_REPORT = 'report';

    public const ACCEPT_AUTO = 'auto';

    public const ACCEPT_MODES = [self::ACCEPT_CHOOSE, self::ACCEPT_REPORT, self::ACCEPT_AUTO];

    /** Heure limite par jour de la semaine : {"6": "12:00"} ; les jours laissés vides suivent l'heure commune */
    protected function cutoffByDay(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? json_decode($value, true) : null,
            set: function (?array $value) {
                $days = collect($value ?? [])->filter(fn ($t, $d) => $t && (int) $d >= 1 && (int) $d <= 7)->mapWithKeys(fn ($t, $d) => [(string) $d => substr($t, 0, 5)]);

                return $days->isEmpty() ? null : $days->toJson();
            },
        );
    }

    /**
     * Heure limite d'un jour (AAAA-MM-JJ, aujourd'hui par défaut, heure de l'entreprise) : celle de ce jour de la
     * semaine si l'administrateur l'a réglée, sinon l'heure limite commune. Null : pas d'heure limite.
     */
    public function cutoffFor(?string $date = null): ?string
    {
        $day = $date ? CarbonImmutable::parse($date) : CarbonImmutable::now($this->timezone ?: config('app.timezone'));

        return ($this->cutoff_by_day ?? [])[(string) $day->isoWeekday()] ?? $this->daily_cutoff_time;
    }

    /** Automatisation suspendue (bouton du planning) : plus d'assignation ni de passage au remplaçant, les alertes restent */
    public function automationPaused(): bool
    {
        return $this->automation_paused_at !== null;
    }

    public const VALIDATION_MANUAL = 'manual';

    public const VALIDATION_AUTO = 'auto';

    public const VALIDATION_RULES = 'rules';

    protected static function booted(): void
    {
        // « auto_confirm_orders » (ancien réglage, toujours accepté par l'API) suit le mode, et inversement
        static::saving(function (Company $company) {
            if ($company->isDirty('validation_mode')) {
                $company->auto_confirm_orders = $company->validation_mode !== self::VALIDATION_MANUAL;
            } elseif ($company->isDirty('auto_confirm_orders')) {
                $company->validation_mode = ! $company->auto_confirm_orders ? self::VALIDATION_MANUAL
                    : ($company->validation_mode === self::VALIDATION_RULES ? self::VALIDATION_RULES : self::VALIDATION_AUTO);
            }
        });

        // Annuaire : une fiche modifiée (nom, présentation, inscriptions, retrait) y apparaît aussitôt
        static::saved(function (Company $company) {
            if ($company->directory_listed || $company->wasChanged('directory_listed')) {
                Directory::forget();
            }
        });
    }

    /** Les courses sont-elles validées dès leur enregistrement ? */
    public function validatesAutomatically(): bool
    {
        return in_array($this->validation_mode ?? self::VALIDATION_MANUAL, [self::VALIDATION_AUTO, self::VALIDATION_RULES], true);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === CompanyStatus::Active;
    }

    /**
     * Lien vers une page de l'entreprise : son adresse sur la plateforme, sinon APP_URL.
     */
    public function url(string $path = ''): string
    {
        return Tenancy::baseUrl($this).($path === '' ? '' : '/'.ltrim($path, '/'));
    }

    /**
     * Adresse de l'entreprise (sous-domaine) : minuscules, chiffres et tirets, 3 à 40
     * caractères, ni réservée (www, admin…) ni déjà prise.
     *
     * @return list<mixed>
     */
    public static function slugRules(?self $ignore = null): array
    {
        return [
            'string', 'min:3', 'max:40', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/',
            Rule::notIn(config('platform.reserved_subdomains')),
            Rule::unique('companies', 'slug')->ignore($ignore),
        ];
    }

    /**
     * Adresse libre proposée à partir du nom (« Rapide Express » → rapide-express).
     */
    public static function freeSlug(string $name): string
    {
        $base = trim(substr(Str::slug($name), 0, 36), '-');
        $base = strlen($base) >= 3 && ! in_array($base, config('platform.reserved_subdomains'), true) ? $base : 'entreprise';
        $slug = $base;
        for ($i = 2; static::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Module inclus dans la formule ; sans formule, l'entreprise a tout (aucune limite).
     */
    public function hasFeature(PlatformFeature $feature): bool
    {
        return $this->plan_id === null || (bool) $this->loadMissing('plan')->plan?->includes($feature);
    }

    /**
     * Modules disponibles pour l'entreprise.
     *
     * @return list<string>
     */
    public function features(): array
    {
        return array_values(array_map(
            fn (PlatformFeature $f) => $f->value,
            array_filter(PlatformFeature::cases(), fn (PlatformFeature $f) => $this->hasFeature($f)),
        ));
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->toDateString() >= now($this->timezone)->toDateString();
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function courierRules(): HasMany
    {
        return $this->hasMany(CourierRule::class);
    }

    public function couriers(): HasMany
    {
        return $this->hasMany(Courier::class);
    }
}
