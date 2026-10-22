<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Périmètre de l'équipe : un membre limité à certaines activités (e-commerce, distribution, MAD) ne voit
 * que leurs courses, clients, formules et factures. Aucun filtre hors requête authentifiée (jobs, console)
 * ni pour l'administrateur, les livreurs et les clients.
 */
class ActivityScope implements Scope
{
    /**
     * @param  string|null  $relation  relation qui porte l'activité (clients : leurs activités), sinon la colonne « activity »
     */
    public function __construct(private readonly ?string $relation = null) {}

    public function apply(Builder $builder, Model $model): void
    {
        $scope = Auth::user()?->activityScope();
        if ($scope === null) {
            return;
        }

        $this->relation === null
            ? $builder->whereIn($model->qualifyColumn('activity'), $scope)
            : $builder->whereHas($this->relation, fn ($q) => $q->whereIn('activity', $scope));
    }
}
