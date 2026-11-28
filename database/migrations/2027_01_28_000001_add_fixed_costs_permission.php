<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Les frais fixes ont leur propre menu et leur propre autorisation (« Saisir les frais fixes »), née de « Encaisser
 * et reverser » : les rôles sur mesure et les comptes qui avaient celle-ci reçoivent la nouvelle, pour que personne
 * ne perde la saisie. Les rôles intégrés sont resynchronisés au déploiement (RolesAndPermissionsSeeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        $new = PermissionModel::findOrCreate('fixed_costs.manage', 'web');
        $wide = PermissionModel::query()->where('name', 'finance.manage')->where('guard_name', 'web')->first();
        if ($wide === null) {
            return;
        }
        foreach (DB::table('role_has_permissions')->where('permission_id', $wide->id)->pluck('role_id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $new->id, 'role_id' => $roleId]);
        }
        $morph = (new User)->getMorphClass();
        foreach (DB::table('model_has_permissions')->where('model_type', $morph)->where('permission_id', $wide->id)->pluck('model_id') as $userId) {
            DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $new->id, 'model_type' => $morph, 'model_id' => $userId]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Rien à retirer : l'autorisation reste inoffensive si l'on revient à la version précédente
    }
};
