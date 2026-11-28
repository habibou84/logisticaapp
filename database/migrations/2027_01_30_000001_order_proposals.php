<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Événements des courses :
 * - un motif peut être une simple information (effect « note ») : la course continue, rien ne change de statut ;
 * - un report ou un retour signalé par le livreur peut demander une validation (équipe ou client) avant de prendre
 *   effet : c'est une proposition (order_proposals), relancée tant que personne n'a répondu.
 * Compatible avec la version précédente : colonnes ajoutées avec une valeur par défaut, nouvelle table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_reasons', function (Blueprint $table) {
            $table->string('effect', 20)->default('status');
            $table->boolean('needs_validation')->default(false);
        });

        // Motifs communs : les reports et les retours du livreur sont désormais vérifiés avant de prendre effet
        DB::table('incident_reasons')->whereNull('company_id')
            ->whereIn('applies_to', ['delivery', 'both'])
            ->where(fn ($q) => $q->where('requires_date', true)->orWhere('triggers_return', true))
            ->update(['needs_validation' => true]);

        Schema::create('order_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_event_id')->nullable()->constrained('order_events')->nullOnDelete();
            $table->foreignId('incident_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            // reschedule : nouvelle date ; return : retour au client
            $table->string('kind', 20);
            $table->date('proposed_date')->nullable();
            // pending, approved, rejected, closed (une autre décision a été prise sur la course)
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            // company ou merchant
            $table->string('decided_by_type', 20)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->unsignedSmallInteger('reminders')->default(0);
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_proposals');
        Schema::table('incident_reasons', function (Blueprint $table) {
            $table->dropColumn(['effect', 'needs_validation']);
        });
    }
};
