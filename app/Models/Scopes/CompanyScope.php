<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Limite automatiquement les requêtes à l'entreprise de l'utilisateur connecté.
 * Aucun filtre pour le super administrateur ni hors requête authentifiée
 * (console, jobs) : ces contextes doivent filtrer explicitement.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if ($user === null || $user->company_id === null) {
            return;
        }

        $builder->where($model->qualifyColumn('company_id'), $user->company_id);
    }
}
