<?php

namespace App\Services\Ratings;

use App\Enums\BillingFormula;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CourierRating;
use App\Models\Order;
use App\Models\User;
use App\Notifications\CourierRatingAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * Notes des livreurs : par le client (course, mise à disposition), par l'équipe (course, évaluation),
 * par le destinataire (page de suivi). Règles et réglages de l'entreprise (Réglages › Notation).
 */
class CourierRatings
{
    public function __construct(private readonly RatingSettings $settings) {}

    /**
     * Le client note le livreur d'une de ses courses terminées (pendant le délai des réglages ; modifiable).
     *
     * @param  array{stars: int, criteria?: list<string>, reasons?: list<string>, comment?: ?string}  $data
     */
    public function byClient(User $actor, Order $order, array $data): CourierRating
    {
        $s = $this->settings->for($order->company);
        if (! $s['client_course']) {
            throw new BusinessRuleException('La notation des livreurs n\'est pas proposée.', 'stars');
        }
        $courier = $this->courierOf($order);
        $this->assertRateable($order, (int) $s['client_edit_hours']);
        $this->assertReason($data, $s);

        return $this->save(['order_id' => $order->id, 'source' => CourierRating::CLIENT], [
            'company_id' => $order->company_id,
            'courier_id' => $courier->id,
            'merchant_id' => $order->merchant_id,
            'scope' => CourierRating::COURSE,
            'author_id' => $actor->id,
            'weight' => $this->hadIncident($order) ? (float) $s['incident_weight'] : 1.0,
            ...$this->fields($data, RatingCriteria::CLIENT),
        ], $s);
    }

    /**
     * Le client en mise à disposition note son livreur attitré pour la semaine ou le mois en cours.
     *
     * @param  array{stars: int, criteria?: list<string>, reasons?: list<string>, comment?: ?string}  $data
     */
    public function byMadClient(User $actor, Courier $courier, array $data): CourierRating
    {
        $company = Company::query()->find($actor->company_id);
        $s = $this->settings->for($company);
        if ($s['client_mad_period'] === 'off') {
            throw new BusinessRuleException('La notation des livreurs mis à disposition n\'est pas proposée.', 'stars');
        }
        $attitre = BillingPlan::withoutGlobalScopes()->where('merchant_id', $actor->merchant_id)
            ->where('formula', BillingFormula::MadContract->value)->activeOn(now()->toDateString())
            ->whereHas('couriers', fn ($q) => $q->whereKey($courier->id))->exists();
        if (! $attitre) {
            throw new BusinessRuleException('Ce livreur n\'est pas mis à votre disposition.', 'courier_id');
        }
        $this->assertReason($data, $s);
        [$start, $end] = $this->period($s['client_mad_period']);

        return $this->save([
            'courier_id' => $courier->id, 'merchant_id' => $actor->merchant_id, 'source' => CourierRating::CLIENT,
            'scope' => CourierRating::PERIOD, 'period_start' => $start,
        ], [
            'company_id' => $courier->company_id,
            'author_id' => $actor->id,
            'period_end' => $end,
            ...$this->fields($data, RatingCriteria::CLIENT),
        ], $s);
    }

    /**
     * L'équipe note un livreur : sur une course, ou évaluation de la période (critères de 1 à 5).
     *
     * @param  array{stars: int, criteria?: array<string, int>, comment?: ?string}  $data
     */
    public function byTeam(User $actor, Courier $courier, ?Order $order, array $data): CourierRating
    {
        $s = $this->settings->for(Company::query()->find($courier->company_id));
        if ($order !== null && ! in_array($courier->id, [$order->delivery_courier_id, $order->pickup_courier_id, $order->return_courier_id], true)) {
            throw new BusinessRuleException('Ce livreur n\'a pas fait cette course.', 'courier_id');
        }
        $criteria = collect($data['criteria'] ?? [])->only(array_keys(RatingCriteria::TEAM))
            ->map(fn ($v) => max(1, min(5, (int) $v)))->filter()->all();

        $key = $order
            ? ['order_id' => $order->id, 'courier_id' => $courier->id, 'source' => CourierRating::TEAM, 'author_id' => $actor->id]
            : ['courier_id' => $courier->id, 'source' => CourierRating::TEAM, 'scope' => CourierRating::PERIOD, 'author_id' => $actor->id, 'period_start' => now()->startOfMonth()->toDateString()];

        return $this->save($key, [
            'company_id' => $courier->company_id,
            'merchant_id' => $order?->merchant_id,
            'scope' => $order ? CourierRating::COURSE : CourierRating::PERIOD,
            'period_end' => $order ? null : now()->endOfMonth()->toDateString(),
            'stars' => max(1, min(5, (int) $data['stars'])),
            'criteria' => $criteria ?: null,
            'reasons' => null,
            'comment' => filled($data['comment'] ?? null) ? trim($data['comment']) : null,
        ], $s);
    }

    /**
     * Le destinataire note la livraison sur la page de suivi (si l'entreprise le propose), une seule fois.
     *
     * @param  array{stars: int, comment?: ?string}  $data
     */
    public function byRecipient(Order $order, array $data): CourierRating
    {
        $s = $this->settings->for($order->company);
        if (! $s['recipient_rating']) {
            throw new BusinessRuleException('La notation n\'est pas proposée.', 'stars');
        }
        if ($order->status !== OrderStatus::Delivered || ! $order->delivered_at || $order->delivered_at->lt(now()->subDays(7))) {
            throw new BusinessRuleException('Cette livraison ne peut plus être notée.', 'stars');
        }
        if (CourierRating::withoutGlobalScopes()->where('order_id', $order->id)->where('source', CourierRating::RECIPIENT)->exists()) {
            throw new BusinessRuleException('Merci, cette livraison a déjà été notée.', 'stars');
        }

        return $this->save(['order_id' => $order->id, 'source' => CourierRating::RECIPIENT], [
            'company_id' => $order->company_id,
            'courier_id' => $this->courierOf($order)->id,
            'merchant_id' => $order->merchant_id,
            'scope' => CourierRating::COURSE,
            'stars' => max(1, min(5, (int) $data['stars'])),
            'comment' => filled($data['comment'] ?? null) ? mb_substr(trim($data['comment']), 0, 500) : null,
        ], $s);
    }

    /** Un responsable écarte une note injuste (ex. client mécontent du prix) ; elle ne compte plus */
    public function neutralize(User $actor, CourierRating $rating, string $reason): CourierRating
    {
        $rating->update(['neutralized_at' => now(), 'neutralized_by' => $actor->id, 'neutralized_reason' => $reason]);

        return $rating;
    }

    public function restore(CourierRating $rating): CourierRating
    {
        $rating->update(['neutralized_at' => null, 'neutralized_by' => null, 'neutralized_reason' => null]);

        return $rating;
    }

    /**
     * Course que le client peut (encore) noter : terminée par un livreur, dans le délai.
     */
    public function canRate(Order $order, ?Company $company = null): bool
    {
        $s = $this->settings->for($company ?? $order->company);
        try {
            $this->courierOf($order);
            $this->assertRateable($order, (int) $s['client_edit_hours']);
        } catch (BusinessRuleException) {
            return false;
        }

        return (bool) $s['client_course'];
    }

    public function courierOf(Order $order): Courier
    {
        $id = $order->delivery_courier_id ?? $order->return_courier_id ?? $order->pickup_courier_id;
        $courier = $id ? Courier::withoutGlobalScopes()->find($id) : null;
        if ($courier === null) {
            throw new BusinessRuleException('Aucun livreur sur cette course.', 'courier_id');
        }

        return $courier;
    }

    private function assertRateable(Order $order, int $hours): void
    {
        $end = $order->delivered_at ?? $order->returned_at;
        if (! in_array($order->status, [OrderStatus::Delivered, OrderStatus::Returned], true) || $end === null) {
            throw new BusinessRuleException('La course pourra être notée une fois terminée.', 'stars');
        }
        if ($end->lt(now()->subHours($hours))) {
            throw new BusinessRuleException("Une course se note dans les {$hours} heures qui suivent sa fin.", 'stars');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $s
     */
    private function assertReason(array $data, array $s): void
    {
        $stars = (int) ($data['stars'] ?? 0);
        if ($stars < 1 || $stars > 5) {
            throw new BusinessRuleException('Choisissez de 1 à 5 étoiles.', 'stars');
        }
        if ($stars <= (int) $s['reason_below'] && empty(array_intersect((array) ($data['reasons'] ?? []), array_keys(RatingCriteria::REASONS)))) {
            throw new BusinessRuleException('Dites-nous ce qui n\'est pas allé : choisissez au moins un motif.', 'reasons');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $criteria
     * @return array<string, mixed>
     */
    private function fields(array $data, array $criteria): array
    {
        $pick = fn (string $field, array $allowed) => array_values(array_intersect(array_unique((array) ($data[$field] ?? [])), array_keys($allowed))) ?: null;

        return [
            'stars' => (int) $data['stars'],
            'criteria' => $pick('criteria', $criteria),
            'reasons' => $pick('reasons', RatingCriteria::REASONS),
            'comment' => filled($data['comment'] ?? null) ? mb_substr(trim($data['comment']), 0, 1000) : null,
        ];
    }

    /** Le livreur avait signalé un problème sur la course (injoignable, refus…) */
    private function hadIncident(Order $order): bool
    {
        return $order->last_incident_reason_id !== null || (int) $order->attempts_count > 0;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function period(string $kind): array
    {
        $now = CarbonImmutable::now();

        return $kind === 'weekly'
            ? [$now->startOfWeek()->toDateString(), $now->endOfWeek()->toDateString()]
            : [$now->startOfMonth()->toDateString(), $now->endOfMonth()->toDateString()];
    }

    /**
     * Enregistre (ou modifie) la note, puis prévient les responsables si elle est mauvaise.
     *
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $s
     */
    private function save(array $key, array $values, array $s): CourierRating
    {
        $rating = CourierRating::withoutGlobalScopes()->firstOrNew($key);
        $wasBad = $rating->exists && $rating->stars <= (int) $s['reason_below'];
        $rating->fill([...$key, ...$values])->save();

        if ($rating->stars <= (int) $s['reason_below'] && ! $wasBad && $rating->source !== CourierRating::TEAM) {
            $this->alertBad($rating);
        }

        return $rating;
    }

    private function alertBad(CourierRating $rating): void
    {
        $rating->loadMissing(['courier.user', 'merchant', 'order']);
        $who = $rating->source === CourierRating::RECIPIENT ? 'Un destinataire' : ($rating->merchant?->business_name ?? 'Un client');
        $reasons = collect($rating->reasons ?? [])->map(fn ($r) => RatingCriteria::REASONS[$r] ?? $r)->implode(', ');
        $users = User::withoutGlobalScopes()->where('company_id', $rating->company_id)->where('status', 'active')
            ->role([Role::Admin->value, Role::ActivityManager->value, Role::Dispatcher->value])->get()
            ->filter(fn (User $u) => $rating->order === null || $u->seesActivity($rating->order->activity));

        Notification::send($users, new CourierRatingAlert(
            $rating->courier,
            'courier_bad_rating',
            "⭐ {$rating->stars}/5 pour {$rating->courier->user?->name}",
            "{$who}".($rating->order ? " (course {$rating->order->tracking_code})" : '').($reasons ? " : {$reasons}" : '').($rating->comment ? " · « {$rating->comment} »" : '').'.',
        ));
    }
}
