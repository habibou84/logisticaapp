<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clés d'accès à l'API publique d'un marchand : seul le hash est conservé
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained();
            $table->string('name', 100);
            $table->string('key_prefix', 12)->unique(); // partie visible : lv_<prefix>_…
            $table->string('key_hash', 64);
            $table->json('scopes');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'merchant_id']);
        });

        // Réponses mémorisées des requêtes POST rejouées avec la même clé d'idempotence (24 h)
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_code')->nullable(); // null = en cours de traitement
            $table->longText('response_body')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['api_key_id', 'key']);
            $table->index('created_at');
        });

        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained();
            $table->string('url', 500);
            $table->json('events');
            $table->text('secret'); // chiffré
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['merchant_id', 'is_active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_subscription_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_id');
            $table->string('event', 40);
            $table->json('payload');
            $table->string('status', 20)->default('pending'); // pending | delivered | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['webhook_subscription_id', 'created_at']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('api_keys');
    }
};
