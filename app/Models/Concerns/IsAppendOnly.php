<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Écritures comptables : jamais supprimées, et seule la colonne de rattachement
 * (au reversement ou à la paie) peut être renseignée ou retirée après coup.
 * Une erreur se corrige par une écriture d'ajustement.
 */
trait IsAppendOnly
{
    public static function bootIsAppendOnly(): void
    {
        static::updating(function ($model) {
            if (array_diff(array_keys($model->getDirty()), $model->linkColumns()) !== []) {
                throw new LogicException('Une écriture comptable ne peut pas être modifiée : passez un ajustement.');
            }
        });

        static::deleting(fn () => throw new LogicException('Une écriture comptable ne peut pas être supprimée.'));
    }

    /**
     * @return list<string>
     */
    protected function linkColumns(): array
    {
        return ['payout_id'];
    }
}
