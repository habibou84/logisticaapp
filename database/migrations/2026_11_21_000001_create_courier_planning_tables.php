<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planning des livreurs : qui ramasse (ou livre) où et quand, remplaçants et absences ;
 * assignation automatique des ramassages selon ce planning (désactivée, à l'essai ou active).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // pickup (ramassage) ou delivery (livraison)
            $table->string('type', 10)->default('pickup');
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_courier_id')->nullable()->constrained('couriers')->nullOnDelete();
            // Où : des zones ou des clients (identifiants)
            $table->json('zone_ids')->nullable();
            $table->json('merchant_ids')->nullable();
            // Quand : jours de la semaine (1 = lundi … 7 = dimanche, vide = tous), période (vide = sans fin)
            $table->json('days')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'type', 'is_active']);
        });

        Schema::create('courier_absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'starts_on', 'ends_on']);
        });

        Schema::table('companies', function (Blueprint $table) {
            // off, trial (essai : rien n'est envoyé, le choix est noté), on
            $table->string('auto_pickup', 10)->default('off');
            $table->date('planning_digest_sent_on')->nullable();
        });

        Schema::table('order_assignments', function (Blueprint $table) {
            // Mission confiée par le planning ou l'assignation automatique (null : à la main)
            $table->string('source', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_assignments', fn (Blueprint $table) => $table->dropColumn('source'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['auto_pickup', 'planning_digest_sent_on']));
        Schema::dropIfExists('courier_absences');
        Schema::dropIfExists('courier_rules');
    }
};
