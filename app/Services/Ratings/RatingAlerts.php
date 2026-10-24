<?php

namespace App\Services\Ratings;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Courier;
use App\Models\User;
use App\Notifications\CourierRatingAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Chaque jour : livreur dont la note passe sous le seuil, ou qui a reçu trop de mauvaises notes en 7 jours
 * (réglages). Une alerte par livreur et par semaine au plus.
 */
class RatingAlerts
{
    public function __construct(
        private readonly RatingSettings $settings,
        private readonly CourierScores $scores,
    ) {}

    public function run(): int
    {
        $sent = 0;
        Company::query()->where('status', 'active')->each(function (Company $company) use (&$sent) {
            $s = $this->settings->for($company);
            $users = null;
            Courier::withoutGlobalScopes()->where('company_id', $company->id)->with('user')
                ->whereHas('user', fn ($q) => $q->where('status', 'active'))->get()
                ->each(function (Courier $courier) use ($s, $company, &$users, &$sent) {
                    $score = $this->scores->for($courier);
                    $low = $score['score'] !== null && $score['score'] < (float) $s['alert_below'];
                    $bad = (int) $s['alert_bad_count'] > 0 && $score['bad_last_7_days'] >= (int) $s['alert_bad_count'];
                    if ((! $low && ! $bad) || ! Cache::add("courier-rating-alert:{$courier->id}", 1, now()->addDays(7))) {
                        return;
                    }
                    $users ??= User::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'active')
                        ->role([Role::Admin->value, Role::ActivityManager->value])->get();
                    $name = $courier->user?->name;
                    Notification::send($users, new CourierRatingAlert(
                        $courier,
                        'courier_low_score',
                        $low ? "⚠️ Note de {$name} : {$score['score']} / 5" : "⚠️ {$name} : {$score['bad_last_7_days']} mauvaises notes en 7 jours",
                        $low
                            ? "Sous le seuil de {$s['alert_below']} / 5. Voir le détail et en parler avec le livreur."
                            : 'Voir les notes et commentaires des clients.',
                    ));
                    $sent++;
                });
        });

        return $sent;
    }
}
