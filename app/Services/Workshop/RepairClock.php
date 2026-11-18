<?php

namespace App\Services\Workshop;

use Carbon\CarbonImmutable;

/**
 * Heure d'une étape : celle du téléphone au moment du clic (gardée hors connexion, envoyée plus tard), si elle est
 * plausible ; sinon l'heure du serveur.
 */
class RepairClock
{
    private const MAX_AGE_HOURS = 72;

    public static function at(?string $value): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        if (! $value) {
            return $now;
        }
        try {
            $at = CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return $now;
        }

        return $at->lt($now->subHours(self::MAX_AGE_HOURS)) || $at->gt($now->addMinutes(5)) ? $now : $at;
    }
}
