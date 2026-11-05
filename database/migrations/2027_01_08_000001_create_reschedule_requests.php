<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colis pas ramassés à leur date : demande de nouvelle date à l'e-commerçant (message du soir ou dispatch), réponse
 * par WhatsApp, par la page de réponse ou par Jibiat Marchand, réglages de l'entreprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reschedule_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            // Lien de la page de réponse : empreinte pour la recherche, lien chiffré pour le renvoyer
            $table->string('token_hash', 64)->unique();
            $table->text('token');
            // Courses dans l'ordre des numéros du message (1, 2, 3…)
            $table->json('order_ids');
            $table->date('missed_day');
            $table->string('origin', 20);
            $table->string('status', 20)->default('pending');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('whatsapp_sent_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->string('answered_via', 20)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('reschedule_request_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('reschedule_evening')->default(false);
            $table->string('reschedule_evening_time', 5)->default('18:00');
            $table->string('reschedule_channel', 20)->default('app_first');
            $table->string('reschedule_no_answer', 20)->default('remind');
            $table->date('reschedule_evening_sent_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['reschedule_evening', 'reschedule_evening_time', 'reschedule_channel', 'reschedule_no_answer', 'reschedule_evening_sent_on']));
        Schema::table('orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('reschedule_request_id'));
        Schema::dropIfExists('reschedule_requests');
    }
};
