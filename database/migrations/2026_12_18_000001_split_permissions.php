<?php

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissions par métier : les permissions larges (réglages, dispatch, gérer les clients…) sont découpées. Un compte
 * qui avait une permission large en plus de son rôle reçoit aussi celles qui en sont nées ; un compte à qui elle
 * était retirée les perd aussi. Les rôles, eux, sont resynchronisés au déploiement (RolesAndPermissionsSeeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, 'web');
        }
        $ids = PermissionModel::query()->pluck('id', 'name');
        $morph = (new User)->getMorphClass();

        foreach (Permission::splits() as $wide => $parts) {
            // Ajoutées : permissions directes du compte
            $userIds = DB::table('model_has_permissions')->where('model_type', $morph)
                ->where('permission_id', $ids[$wide] ?? 0)->pluck('model_id');
            foreach ($userIds as $userId) {
                foreach ($parts as $part) {
                    DB::table('model_has_permissions')->insertOrIgnore([
                        'permission_id' => $ids[$part], 'model_type' => $morph, 'model_id' => $userId,
                    ]);
                }
            }

            // Retirées : réglage fin du compte
            DB::table('users')->whereNotNull('denied_permissions')->orderBy('id')->each(function ($user) use ($wide, $parts) {
                $denied = json_decode($user->denied_permissions, true) ?: [];
                if (in_array($wide, $denied, true)) {
                    DB::table('users')->where('id', $user->id)
                        ->update(['denied_permissions' => json_encode(array_values(array_unique([...$denied, ...$parts])))]);
                }
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Les nouvelles permissions restent : sans effet tant que le code ne les vérifie pas
    }
};
