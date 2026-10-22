<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acceptation des missions et sécurité de l'assignation automatique : règle de l'entreprise (le livreur accepte
 * ou refuse, acceptées d'office avec empêchement possible, acceptées d'office), exception par livreur, livreur
 * injoignable, ramassage pas commencé avant l'heure limite, suspension de l'automatisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('mission_acceptance', 10)->default('choose');
            $table->unsignedSmallInteger('unreachable_minutes')->default(20);
            $table->unsignedSmallInteger('late_pickup_alert_minutes')->default(60);
            $table->timestamp('automation_paused_at')->nullable();
            $table->foreignId('automation_paused_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('couriers', function (Blueprint $table) {
            $table->string('mission_acceptance', 10)->nullable();
            $table->timestamp('last_seen_at')->nullable();
        });

        Schema::table('order_assignments', function (Blueprint $table) {
            $table->timestamp('unreachable_at')->nullable();
            $table->timestamp('late_alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_assignments', fn (Blueprint $table) => $table->dropColumn(['unreachable_at', 'late_alerted_at']));
        Schema::table('couriers', fn (Blueprint $table) => $table->dropColumn(['mission_acceptance', 'last_seen_at']));
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('automation_paused_by');
            $table->dropColumn(['mission_acceptance', 'unreachable_minutes', 'late_pickup_alert_minutes', 'automation_paused_at']);
        });
    }
};
