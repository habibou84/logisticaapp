<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gestion des véhicules : parc (fiche, propriétaire, état, compteur, plan d'entretien), papiers et échéances
 * (véhicule ou permis du livreur), historique des remises, états des lieux, carnet (entretien, réparations,
 * sinistres, amendes, carburant), location-vente ou location avec ses versements ; réglages de l'entreprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // moto | velo | voiture | tricycle | pieton (même liste que le livreur)
            $table->string('type', 20);
            $table->string('plate', 30);
            $table->string('brand', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('color', 30)->nullable();
            $table->string('vin', 60)->nullable();
            $table->string('photo_path')->nullable();
            // Capacité : colis, poids (kg), coffre
            $table->unsignedSmallInteger('capacity_parcels')->nullable();
            $table->unsignedSmallInteger('capacity_kg')->nullable();
            $table->boolean('has_box')->default(false);
            // company | courier | rental | lease
            $table->string('ownership', 12)->default('company');
            $table->foreignId('owner_courier_id')->nullable()->constrained('couriers')->nullOnDelete();
            // active | broken | maintenance | immobilized | out | sold
            $table->string('status', 12)->default('active');
            // Livreur qui a le véhicule en ce moment (attitré ou pour la journée)
            $table->foreignId('courier_id')->nullable()->constrained('couriers')->nullOnDelete();
            $table->unsignedInteger('odometer')->default(0);
            $table->timestamp('odometer_at')->nullable();
            $table->unsignedInteger('purchase_price')->nullable();
            $table->date('purchased_on')->nullable();
            // Plan d'entretien : [{key, label, icon, every_km, every_months, last_km, last_on}]
            $table->json('maintenance_plan')->nullable();
            $table->json('maintenance_reminded')->nullable();
            // État changé par un événement terrain (panne, accident) : remis en service à sa fin
            $table->foreignId('field_event_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('vehicle_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->cascadeOnDelete();
            // Permis de conduire : papier du livreur
            $table->foreignId('courier_id')->nullable()->constrained()->cascadeOnDelete();
            // insurance | inspection | tax | registration | transport | license | other
            $table->string('kind', 20);
            $table->string('label', 80)->nullable();
            $table->string('reference', 80)->nullable();
            $table->string('issuer', 80)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('cost')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_mime', 80)->nullable();
            // Rappels envoyés : 0 aucun, 1 premier, 2 second, 3 date passée
            $table->unsignedTinyInteger('reminded')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'ends_on']);
        });

        Schema::create('vehicle_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            // assigned (attitré) | day (pris pour la journée)
            $table->string('kind', 10)->default('assigned');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('odometer_start')->nullable();
            $table->unsignedInteger('odometer_end')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'starts_at']);
        });

        Schema::create('vehicle_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            // start | end
            $table->string('moment', 6)->default('start');
            $table->unsignedInteger('odometer')->nullable();
            // reserve | quarter | half | three_quarters | full
            $table->string('fuel', 16)->nullable();
            // {brakes: ok|problem, …}
            $table->json('items')->nullable();
            $table->unsignedTinyInteger('problems')->default(0);
            $table->text('note')->nullable();
            $table->json('photos')->nullable();
            // Distance du trajet GPS de la journée (état des lieux du retour)
            $table->decimal('gps_km', 8, 1)->nullable();
            $table->timestamps();

            $table->index(['courier_id', 'created_at']);
        });

        Schema::create('vehicle_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained()->nullOnDelete();
            // maintenance | repair | claim | fine | fuel | other
            $table->string('kind', 12);
            $table->date('done_on');
            $table->unsignedInteger('odometer')->nullable();
            $table->string('label', 160)->nullable();
            $table->string('garage', 80)->nullable();
            $table->unsignedInteger('amount')->default(0);
            $table->decimal('liters', 7, 2)->nullable();
            // company | courier : qui a payé
            $table->string('paid_by', 10)->default('company');
            // Part retenue au livreur (amende, dégâts), et ce qui a vraiment été retenu (plafond)
            $table->unsignedInteger('courier_share')->nullable();
            $table->unsignedInteger('charged')->nullable();
            // Entretien du plan fait (clés), jours d'immobilisation
            $table->json('plan_keys')->nullable();
            $table->unsignedSmallInteger('immobilized_days')->nullable();
            $table->foreignId('field_event_id')->nullable()->constrained()->nullOnDelete();
            $table->json('photos')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'done_on']);
            $table->index(['company_id', 'kind', 'done_on']);
        });

        Schema::create('vehicle_leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            // purchase (location-vente) | rental (location simple)
            $table->string('kind', 10)->default('purchase');
            $table->unsignedInteger('amount');
            // day | week | month
            $table->string('period', 6)->default('day');
            $table->unsignedTinyInteger('days_per_week')->default(7);
            // Prix total (location-vente) ; vide pour une location sans fin
            $table->unsignedInteger('total_amount')->nullable();
            $table->date('starts_on');
            // payroll (retenue sur la paie) | cash (payé en caisse)
            $table->string('payment', 8)->default('payroll');
            // active | completed | cancelled
            $table->string('status', 10)->default('active');
            $table->unsignedTinyInteger('late_level')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_lease_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_lease_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->date('paid_on');
            // payroll | cash | other
            $table->string('method', 8);
            $table->foreignId('courier_earning_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 160)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->json('vehicle_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('vehicle_settings'));
        Schema::dropIfExists('vehicle_lease_payments');
        Schema::dropIfExists('vehicle_leases');
        Schema::dropIfExists('vehicle_logs');
        Schema::dropIfExists('vehicle_checks');
        Schema::dropIfExists('vehicle_assignments');
        Schema::dropIfExists('vehicle_documents');
        Schema::dropIfExists('vehicles');
    }
};
