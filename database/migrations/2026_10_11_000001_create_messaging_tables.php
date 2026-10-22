<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Messages WhatsApp au destinataire (colis en route, code de livraison)
            $table->boolean('notify_recipients')->default(true)->after('return_fee_percent');
            // SMS quand le message WhatsApp n'a pas pu être remis
            $table->boolean('sms_fallback')->default(true)->after('notify_recipients');
        });

        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Numéro de l'entreprise ; le numéro propre à un marchand viendra plus tard
            $table->string('owner_type', 20)->default('company');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('provider', 20)->default('meta_cloud');
            $table->string('waba_id', 50)->nullable();
            $table->string('phone_number_id', 50)->nullable()->unique();
            $table->string('display_phone', 20)->nullable();
            $table->text('access_token')->nullable(); // chiffré
            $table->string('status', 20)->default('pending'); // pending | connected | disconnected
            $table->timestamps();

            $table->index(['company_id', 'owner_type', 'owner_id']);
        });

        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('language', 10)->default('fr');
            $table->string('category', 20)->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | paused | disabled
            $table->string('rejected_reason')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['whatsapp_account_id', 'name', 'language']);
        });

        Schema::create('outbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20); // whatsapp | sms
            $table->foreignId('whatsapp_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to', 20);
            $table->string('recipient_type', 20); // merchant | recipient | test
            $table->string('event', 50)->nullable();
            $table->string('template_name', 100)->nullable();
            $table->json('payload')->nullable(); // paramètres du modèle
            $table->text('body'); // texte rendu (journal, SMS de repli)
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            // Message WhatsApp dont ce SMS est le repli
            $table->foreignId('fallback_for_id')->nullable()->constrained('outbound_messages')->nullOnDelete();
            $table->string('status', 20)->default('queued'); // queued | sent | delivered | read | failed
            $table->string('provider_message_id')->nullable()->unique();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'status']);
            $table->index('order_id');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('event', 50);
            $table->json('channels'); // ["whatsapp"] ; les notifications dans l'application sont toujours actives
            $table->timestamps();

            $table->unique(['merchant_id', 'event']);
        });

        Schema::create('scheduled_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('frequency', 10); // daily | weekly
            $table->time('send_time')->default('19:00');
            $table->unsignedTinyInteger('weekday')->default(1); // hebdomadaire : 1 = lundi … 7 = dimanche
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'frequency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_reports');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('outbound_messages');
        Schema::dropIfExists('whatsapp_templates');
        Schema::dropIfExists('whatsapp_accounts');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['notify_recipients', 'sms_fallback']);
        });
    }
};
