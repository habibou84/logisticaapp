<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Périmètre par zones et dépôts d'un membre de l'équipe (son rôle sur mesure ou son compte) : il ne voit que les
 * courses ramassées ou livrées dans ses zones, ou passées par ses dépôts. Aucun filtre hors requête authentifiée
 * (jobs, console) ni pour l'administrateur, les livreurs et les clients.
 */
class PerimeterScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();
        $zones = $user?->zoneScope();
        $hubs = $user?->hubScope();
        if ($zones === null && $hubs === null) {
            return;
        }

        $builder->where(function (Builder $q) use ($model, $zones, $hubs) {
            if ($zones !== null) {
                $q->whereIn($model->qualifyColumn('pickup_zone_id'), $zones)
                    ->orWhereIn($model->qualifyColumn('delivery_zone_id'), $zones);
            }
            if ($hubs !== null) {
                $q->orWhereIn($model->qualifyColumn('pickup_hub_id'), $hubs);
            }
        });
    }
}
