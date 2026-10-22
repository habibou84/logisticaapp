<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Activités : e-commerce, distribution, mise à disposition (MAD). Un client en a une ou plusieurs ; chaque
 * course, formule et facture appartient à une seule ; l'équipe peut être limitée à certaines (périmètre),
 * avec des permissions ajoutées ou retirées une à une.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            // ecommerce | distribution | mad
            $table->string('activity', 20);
            $table->unique(['merchant_id', 'activity']);
            $table->index('activity');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('activity', 20)->default('ecommerce')->after('source');
            $table->index(['company_id', 'activity', 'created_at']);
        });

        Schema::table('billing_plans', function (Blueprint $table) {
            $table->string('activity', 20)->default('distribution')->after('formula');
        });

        Schema::table('client_invoices', function (Blueprint $table) {
            $table->string('activity', 20)->default('distribution')->after('merchant_id');
            $table->dropUnique(['merchant_id', 'period']);
            $table->unique(['merchant_id', 'period', 'activity']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Périmètre de l'équipe : activités visibles (vide : toutes)
            $table->json('activities')->nullable();
            // Réglage fin : permissions retirées au rôle (celles ajoutées sont des permissions directes)
            $table->json('denied_permissions')->nullable();
        });

        // Existant : formules MAD, courses de leur formule, clients selon leurs formules (e-commerce sinon)
        DB::table('billing_plans')->where('formula', 'mad_contract')->update(['activity' => 'mad']);
        DB::table('client_invoices')->update(['activity' => 'distribution']);
        foreach (DB::table('billing_plans')->get(['id', 'activity']) as $plan) {
            DB::table('orders')->where('billing_plan_id', $plan->id)->update(['activity' => $plan->activity]);
            DB::table('client_invoices')->whereIn('merchant_id', DB::table('billing_plans')->where('id', $plan->id)->select('merchant_id'))
                ->where('activity', 'distribution')->update(['activity' => $plan->activity]);
        }
        foreach (DB::table('merchants')->pluck('id') as $merchantId) {
            $activities = DB::table('billing_plans')->where('merchant_id', $merchantId)->distinct()->pluck('activity')->all() ?: ['ecommerce'];
            if (DB::table('orders')->where('merchant_id', $merchantId)->where('activity', 'ecommerce')->exists()) {
                $activities[] = 'ecommerce';
            }
            foreach (array_unique($activities) as $activity) {
                DB::table('merchant_activities')->insert(['merchant_id' => $merchantId, 'activity' => $activity]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['activities', 'denied_permissions']));
        Schema::table('client_invoices', function (Blueprint $table) {
            $table->dropUnique(['merchant_id', 'period', 'activity']);
            $table->unique(['merchant_id', 'period']);
            $table->dropColumn('activity');
        });
        Schema::table('billing_plans', fn (Blueprint $table) => $table->dropColumn('activity'));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'activity', 'created_at']);
            $table->dropColumn('activity');
        });
        Schema::dropIfExists('merchant_activities');
    }
};
