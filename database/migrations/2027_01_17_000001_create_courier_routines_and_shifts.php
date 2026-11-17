<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pointage des livreurs à leur lieu de début :
 *  - parcours (routine du matin d'un livreur en MAD avant d'arriver chez le client) et leurs étapes ;
 *  - pointages du jour (un par livreur et par jour) ;
 *  - tentatives refusées (trop loin, position imprécise, trop tôt), pour le contrôle par l'équipe ;
 *  - réglages de l'entreprise (agence, distance autorisée, précision, heure de début).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_routines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            // Vide : tous les livreurs du contrat
            $table->foreignId('courier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->json('days');
            // Heure de départ (« 07:30 ») ; vide : heure de début du contrat
            $table->string('starts_at', 5)->nullable();
            $table->boolean('is_active')->default(true);
            // Qui l'a écrit en dernier : l'équipe ou le client
            $table->string('source', 10)->default('staff');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['billing_plan_id', 'is_active']);
        });

        Schema::create('courier_routine_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_routine_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            // destination (annuaire) ou merchant (siège du client)
            $table->string('place_type', 12);
            $table->foreignId('destination_id')->nullable()->constrained()->nullOnDelete();
            // pickup (récupérer), drop (déposer), visit (passer)
            $table->string('action', 10)->default('pickup');
            $table->string('instructions', 255)->nullable();
            $table->string('expected_time', 5)->nullable();
            $table->timestamps();

            $table->index(['courier_routine_id', 'position']);
        });

        Schema::create('courier_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            // mad (chez un client ou sur son parcours) ou agency (agence de l'entreprise)
            $table->string('kind', 10);
            $table->foreignId('billing_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('courier_routine_id')->nullable()->constrained()->nullOnDelete();
            // Lieu tel qu'il était ce jour-là (l'historique ne bouge pas si le parcours change)
            $table->string('place_label', 191)->nullable();
            $table->decimal('place_lat', 10, 7)->nullable();
            $table->decimal('place_lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('radius')->nullable();
            $table->timestamp('expected_at')->nullable();
            $table->timestamp('clocked_in_at');
            $table->unsignedInteger('late_minutes')->default(0);
            // Position du livreur au pointage
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('accuracy')->nullable();
            $table->unsignedInteger('distance')->nullable();
            // gps (par le livreur sur place) ou manual (par un responsable, avec un motif)
            $table->string('method', 10)->default('gps');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['courier_id', 'work_date']);
            $table->index(['company_id', 'work_date']);
        });

        Schema::create('courier_shift_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('place_label', 191)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('accuracy')->nullable();
            $table->unsignedInteger('distance')->nullable();
            // too_far, inaccurate, too_early, no_position
            $table->string('reason', 20);
            $table->timestamp('attempted_at');

            $table->index(['company_id', 'work_date']);
            $table->index(['courier_id', 'work_date']);
        });

        Schema::table('companies', fn (Blueprint $table) => $table->json('shift_settings')->nullable());
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('shift_settings'));
        Schema::dropIfExists('courier_shift_attempts');
        Schema::dropIfExists('courier_shifts');
        Schema::dropIfExists('courier_routine_stops');
        Schema::dropIfExists('courier_routines');
    }
};
