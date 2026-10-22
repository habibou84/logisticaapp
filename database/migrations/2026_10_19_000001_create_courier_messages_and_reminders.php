<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Délai avant de relancer un problème signalé par un livreur et toujours non traité (0 = pas de relance)
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('field_alert_reminder_minutes')->default(10)->after('whatsapp_orders');
        });

        // Consignes du dispatch à un livreur sur une course (réponse à une remontée ou message libre)
        Schema::create('courier_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reply_to_event_id')->nullable()->constrained('order_events')->nullOnDelete();
            $table->string('body', 500);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable(); // « Compris » du livreur
            $table->timestamps();

            $table->index(['courier_id', 'order_id']);
            $table->index('reply_to_event_id');
        });

        // Relances envoyées pour une remontée terrain non traitée
        Schema::create('field_report_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_event_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('count')->default(0);
            $table->timestamp('last_reminded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_report_reminders');
        Schema::dropIfExists('courier_messages');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('field_alert_reminder_minutes'));
    }
};
