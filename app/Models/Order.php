<?php

namespace App\Models;

use App\Enums\AssignmentType;
use App\Enums\FeePayer;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Concerns\BelongsToCompany;
use App\Support\PhoneNumber;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    // Alphabet sans caractères ambigus (0/O, 1/I/L) pour les codes de suivi
    private const TRACKING_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    protected $fillable = [
        'company_id',
        'merchant_id',
        'merchant_reference',
        'source',
        'merchant_alerts_muted',
        'created_by',
        'pickup_zone_id',
        'pickup_hub_id',
        'pickup_address',
        'pickup_landmark',
        'pickup_contact_name',
        'pickup_phone',
        'pickup_lat',
        'pickup_lng',
        'recipient_id',
        'recipient_name',
        'recipient_phone',
        'recipient_phone2',
        'delivery_zone_id',
        'delivery_address',
        'delivery_landmark',
        'delivery_lat',
        'delivery_lng',
        'delivery_scheduled_date',
        'delivery_time_slot',
        'is_shipping',
        'description',
        'package_size',
        'weight_kg',
        'is_fragile',
        'is_express',
        'merchant_note',
        'delivery_fee',
        'surcharges_total',
        'pricing_details',
        'fee_payer',
        'items_amount',
        'cod_amount',
        'max_attempts',
        'delivery_code',
    ];

    protected $hidden = ['delivery_code'];

    protected function casts(): array
    {
        return [
            'merchant_alerts_muted' => 'boolean',
            'status' => OrderStatus::class,
            'fee_payer' => FeePayer::class,
            'pricing_details' => 'array',
            'delivery_code' => 'encrypted',
            'pickup_lat' => 'float',
            'pickup_lng' => 'float',
            'delivery_lat' => 'float',
            'delivery_lng' => 'float',
            'weight_kg' => 'float',
            'is_fragile' => 'boolean',
            'is_express' => 'boolean',
            'is_shipping' => 'boolean',
            'shipping_fee' => 'integer',
            'return_requested' => 'boolean',
            'is_test' => 'boolean',
            'delivery_fee' => 'integer',
            'surcharges_total' => 'integer',
            'items_amount' => 'integer',
            'cod_amount' => 'integer',
            'collected_amount' => 'integer',
            'attempts_count' => 'integer',
            'max_attempts' => 'integer',
            'delivery_scheduled_date' => 'date:Y-m-d',
            'confirmed_at' => 'datetime',
            'prepared_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'delivery_code_unverified_at' => 'datetime',
            'held_since' => 'datetime',
            'lost_at' => 'datetime',
            'hold_escalated_at' => 'datetime',
            'hold_alerted_at' => 'datetime',
            'returned_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'unassigned_alerted_at' => 'datetime',
            'unassigned_escalated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->tracking_code ??= static::generateTrackingCode();
            $order->delivery_code ??= str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $order->status ??= OrderStatus::Pending;
        });

        // Heure d'entrée dans le statut : base du délai « sans livreur » ; un nouveau statut
        // relance le décompte et les alertes
        static::saving(function (Order $order) {
            if (! $order->exists || $order->isDirty('status')) {
                $order->status_changed_at = now();
                $order->unassigned_alerted_at = null;
                $order->unassigned_escalated_at = null;
            }
        });
    }

    public static function generateTrackingCode(): string
    {
        do {
            $random = '';
            for ($i = 0; $i < 8; $i++) {
                $random .= self::TRACKING_ALPHABET[random_int(0, strlen(self::TRACKING_ALPHABET) - 1)];
            }
            $code = 'LV-'.substr($random, 0, 4).'-'.substr($random, 4);
        } while (static::withoutGlobalScopes()->where('tracking_code', $code)->exists());

        return $code;
    }

    protected function recipientPhone(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => PhoneNumber::normalize($value) ?? $value);
    }

    protected function recipientPhone2(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => PhoneNumber::normalize($value) ?? $value);
    }

    protected function pickupPhone(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => PhoneNumber::normalize($value) ?? $value);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    public function pickupZone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'pickup_zone_id');
    }

    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'delivery_zone_id');
    }

    public function pickupHub(): BelongsTo
    {
        return $this->belongsTo(Hub::class, 'pickup_hub_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function pickupCourier(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'pickup_courier_id');
    }

    public function deliveryCourier(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'delivery_courier_id');
    }

    public function returnCourier(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'return_courier_id');
    }

    public function returnSlip(): BelongsTo
    {
        return $this->belongsTo(ReturnSlip::class);
    }

    /**
     * Livreur qui a le colis en main (pas encore rendu au dépôt, livré ou retourné).
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'held_by_courier_id');
    }

    /**
     * Le colis passe dans les mains d'un livreur, ou revient au dépôt (null).
     */
    public function handTo(?int $courierId): void
    {
        if ($courierId === $this->held_by_courier_id) {
            return;
        }

        $this->held_by_courier_id = $courierId;
        $this->held_since = $courierId ? now() : null;
        $this->hold_alerted_at = null;
        $this->hold_escalated_at = null;
    }

    public function lastIncidentReason(): BelongsTo
    {
        return $this->belongsTo(IncidentReason::class, 'last_incident_reason_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(OrderAssignment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    public function cashCollection(): HasOne
    {
        return $this->hasOne(CashCollection::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(OrderExpense::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OrderAttachment::class);
    }

    /**
     * Dernière décision prise par le marchand sur un colis non livré : report ou retour demandé.
     */
    public function merchantDecision(): HasOne
    {
        return $this->hasOne(OrderEvent::class)->ofMany(['id' => 'max'], fn (Builder $q) => $q
            ->whereIn('actor_role', [Role::MerchantOwner->value, Role::MerchantStaff->value])
            ->where(fn (Builder $q) => $q->where('type', OrderEventType::ReturnRequested->value)
                ->orWhere(fn (Builder $q) => $q->where('type', OrderEventType::StatusChanged->value)->where('to_status', OrderStatus::Rescheduled->value))));
    }

    /**
     * Une décision est-elle attendue ? Échec de livraison sans retour demandé, sans report
     * et sans livreur reparti : la file « À décider » de l'agence et « À régler » du marchand.
     */
    public function needsDecision(): bool
    {
        return $this->status === OrderStatus::DeliveryFailed
            && ! $this->return_requested
            && ! $this->assignments()->active()->exists();
    }

    /**
     * Report encore modifiable (nouvelle date ou retour) : aucun livreur n'est reparti avec le colis.
     */
    public function decisionEditable(): bool
    {
        return $this->status === OrderStatus::Rescheduled && ! $this->assignments()->active()->exists();
    }

    /** Même règle, en requête */
    public function scopeNeedingDecision(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::DeliveryFailed)
            ->where('return_requested', false)
            ->whereDoesntHave('assignments', fn ($q) => $q->active());
    }

    /** Jour de livraison : la date prévue, sinon le jour de création */
    public const DELIVERY_DAY_SQL = 'COALESCE(DATE(orders.delivery_scheduled_date), DATE(orders.created_at))';

    /**
     * Courses d'une période, selon leur jour de livraison (« delivery ») ou leur jour de création (« created ») :
     * une course reportée de lundi à mercredi passe au mercredi.
     */
    public function scopeForPeriod(Builder $query, ?string $from, ?string $to, string $basis = 'created'): Builder
    {
        $column = $basis === 'delivery' ? self::DELIVERY_DAY_SQL : 'DATE(orders.created_at)';

        return $query
            ->when($from, fn ($q) => $q->whereRaw("{$column} >= ?", [$from]))
            ->when($to, fn ($q) => $q->whereRaw("{$column} <= ?", [$to]));
    }

    /**
     * Courses dues au plus tard ce jour-là (jour de livraison passé ou égal) : le travail du jour et les retards.
     */
    public function scopeDueBy(Builder $query, string $day): Builder
    {
        return $query->whereRaw(self::DELIVERY_DAY_SQL.' <= ?', [$day]);
    }

    /**
     * Courses d'un jour pour la liste de l'agence : celles à livrer ce jour-là et celles encore en cours dont
     * le jour est déjà passé (en retard). Pour demain, les courses d'aujourd'hui ne sont pas encore en retard.
     */
    public function scopeOfDay(Builder $query, string $day): Builder
    {
        $final = OrderStatus::values(array_filter(OrderStatus::cases(), fn (OrderStatus $s) => $s->isFinal()));
        $overdue = min($day, today()->toDateString());

        return $query->where(fn ($q) => $q->whereRaw(self::DELIVERY_DAY_SQL.' = ?', [$day])
            ->orWhere(fn ($q) => $q->whereRaw(self::DELIVERY_DAY_SQL.' < ?', [$overdue])->whereNotIn('status', $final)));
    }

    public function activeAssignment(AssignmentType $type): ?OrderAssignment
    {
        return $this->assignments()->active()->where('type', $type->value)->latest('id')->first();
    }

    /**
     * Courses visibles par un utilisateur : toute l'entreprise pour le personnel,
     * celles du marchand pour ses comptes, celles qui lui ont été assignées pour un livreur.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->merchant_id !== null) {
            return $query->where('merchant_id', $user->merchant_id);
        }

        if ($user->isCourier()) {
            return $query->whereHas('assignments', fn ($q) => $q->where('courier_id', $user->courier?->id ?? 0));
        }

        return $query;
    }

    /**
     * Total à encaisser auprès du destinataire.
     */
    public static function computeCodAmount(int $itemsAmount, int $fees, FeePayer $payer): int
    {
        return $itemsAmount + ($payer === FeePayer::Recipient ? $fees : 0);
    }

    /**
     * Libellé du statut : un colis d'une zone d'expédition « livré » a été remis au transporteur.
     */
    public function statusLabel(): string
    {
        return match (true) {
            $this->is_shipping && $this->status === OrderStatus::Delivered => 'Expédié',
            $this->fromWarehouse() && $this->status === OrderStatus::Confirmed => 'À préparer',
            $this->fromWarehouse() && $this->status === OrderStatus::AtHub => 'Préparé au dépôt',
            $this->fromWarehouse() && $this->status === OrderStatus::Returned => 'Remis en stock',
            default => $this->status->label(),
        };
    }

    /**
     * Commande préparée dans un entrepôt de l'entreprise : pas de ramassage chez le marchand.
     */
    public function fromWarehouse(): bool
    {
        return $this->pickup_hub_id !== null;
    }

    public function totalFees(): int
    {
        return $this->delivery_fee + $this->surcharges_total;
    }
}
