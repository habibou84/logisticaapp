<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Nouveau module « Statistiques avancées » : ajouté à toutes les formules existantes,
 * pour qu'aucune entreprise ne perde un écran qu'elle a déjà. Le super administrateur
 * le retire ensuite des formules d'entrée de gamme dans la console (Formules).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')->orderBy('id')->each(function ($plan) {
            $features = json_decode($plan->features ?? '[]', true) ?: [];
            if (! in_array('advanced_stats', $features, true)) {
                DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode([...$features, 'advanced_stats'])]);
            }
        });
    }

    public function down(): void
    {
        DB::table('plans')->orderBy('id')->each(function ($plan) {
            $features = array_values(array_diff(json_decode($plan->features ?? '[]', true) ?: [], ['advanced_stats']));
            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features)]);
        });
    }
};
