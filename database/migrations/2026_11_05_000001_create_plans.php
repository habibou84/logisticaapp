<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Formules d'abonnement de la plateforme (définies par le super administrateur) et formule
 * de chaque entreprise. Sans formule, une entreprise n'a aucune limite (installations
 * antérieures, entreprise unique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description', 500)->nullable();
            // Prix mensuel du forfait, en francs CFA
            $table->unsignedInteger('price_monthly')->default(0);
            // Courses incluses par mois (vide = illimité) et prix de chaque course au-delà
            $table->unsignedInteger('included_orders')->nullable();
            $table->unsignedInteger('overage_per_order')->default(0);
            // Livreurs actifs au plus (vide = illimité)
            $table->unsignedInteger('max_couriers')->nullable();
            // Modules inclus (App\Enums\PlatformFeature)
            $table->json('features');
            // Formule proposée aux nouvelles entreprises ; les formules retirées restent attribuées
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('status')->constrained('plans')->restrictOnDelete();
            // Fin de l'essai gratuit (inclus) : la facturation commence le lendemain
            $table->date('trial_ends_at')->nullable()->after('plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn('trial_ends_at');
        });
        Schema::dropIfExists('plans');
    }
};
