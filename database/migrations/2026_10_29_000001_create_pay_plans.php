<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Plans de rémunération des livreurs, propres à chaque entreprise
        Schema::create('pay_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Plan personnel d'un livreur (exception), sinon plan partagé
            $table->foreignId('courier_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            // Ramassage payé par colis, ou par passage chez le marchand (+ montant par colis supplémentaire)
            $table->string('pickup_mode', 20)->default('per_parcel');
            $table->unsignedInteger('pickup_extra_parcel_amount')->default(0);
            // Bornes du gain par course et par étape (null : pas de borne)
            $table->unsignedInteger('min_amount')->nullable();
            $table->unsignedInteger('max_amount')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'courier_id']);
        });

        Schema::create('pay_plan_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_plan_id')->constrained()->cascadeOnDelete();
            $table->string('event', 20);       // pickup | delivery | failed_attempt | return | shipping
            $table->string('calc', 20);        // fixed | percent_fee | percent_collected | zone_grid
            $table->unsignedInteger('amount')->default(0);           // montant fixe, ou montant par défaut de la grille
            $table->decimal('percent', 5, 2)->nullable();
            $table->json('zone_amounts')->nullable();                // grille : { zone_id: montant }
            $table->json('conditions')->nullable();                  // zones, express, fragile, véhicules, motifs d'échec
            $table->string('label', 100)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('couriers', function (Blueprint $table) {
            // null : plan par défaut de l'entreprise
            $table->foreignId('pay_plan_id')->nullable()->after('vehicle_plate')->constrained()->nullOnDelete();
        });

        // Détail du gain : plan et règle qui l'ont produit (montant figé au moment du gain)
        Schema::table('courier_earnings', function (Blueprint $table) {
            $table->foreignId('pay_plan_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
            $table->foreignId('pay_plan_rule_id')->nullable()->after('pay_plan_id')->constrained()->nullOnDelete();
            $table->string('detail')->nullable()->after('description');
        });

        $this->migrateCommissions();

        Schema::table('couriers', function (Blueprint $table) {
            $table->dropColumn(['pickup_commission', 'delivery_commission', 'return_commission']);
        });
    }

    /**
     * Reprise : les montants saisis sur chaque livreur deviennent son plan personnel ;
     * chaque entreprise reçoit un plan par défaut (vide : à compléter).
     */
    private function migrateCommissions(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            DB::table('pay_plans')->insert([
                'company_id' => $companyId, 'name' => 'Plan standard', 'is_default' => true,
                'pickup_mode' => 'per_parcel', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $couriers = DB::table('couriers')
            ->join('users', 'users.id', '=', 'couriers.user_id')
            ->where(fn ($q) => $q->where('pickup_commission', '>', 0)->orWhere('delivery_commission', '>', 0)->orWhere('return_commission', '>', 0))
            ->get(['couriers.id', 'couriers.company_id', 'users.name', 'pickup_commission', 'delivery_commission', 'return_commission']);

        foreach ($couriers as $courier) {
            $planId = DB::table('pay_plans')->insertGetId([
                'company_id' => $courier->company_id, 'courier_id' => $courier->id, 'name' => 'Plan de '.$courier->name,
                'is_default' => false, 'pickup_mode' => 'per_parcel', 'created_at' => $now, 'updated_at' => $now,
            ]);

            foreach (['pickup' => $courier->pickup_commission, 'delivery' => $courier->delivery_commission, 'return' => $courier->return_commission] as $event => $amount) {
                if ($amount > 0) {
                    DB::table('pay_plan_rules')->insert([
                        'pay_plan_id' => $planId, 'event' => $event, 'calc' => 'fixed', 'amount' => $amount,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }

            DB::table('couriers')->where('id', $courier->id)->update(['pay_plan_id' => $planId]);
        }
    }

    public function down(): void
    {
        Schema::table('couriers', function (Blueprint $table) {
            $table->unsignedInteger('pickup_commission')->default(0);
            $table->unsignedInteger('delivery_commission')->default(0);
            $table->unsignedInteger('return_commission')->default(0);
        });
        Schema::table('courier_earnings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pay_plan_rule_id');
            $table->dropConstrainedForeignId('pay_plan_id');
            $table->dropColumn('detail');
        });
        Schema::table('couriers', fn (Blueprint $table) => $table->dropConstrainedForeignId('pay_plan_id'));
        Schema::dropIfExists('pay_plan_rules');
        Schema::dropIfExists('pay_plans');
    }
};
