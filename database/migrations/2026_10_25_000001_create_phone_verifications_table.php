<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vérification d'un numéro (inscription d'un e-commerçant, mot de passe oublié) :
        // message WhatsApp envoyé par la personne, ou code reçu par SMS.
        Schema::create('phone_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 20);
            $table->string('phone', 20);
            // Jeton gardé par le navigateur (empreinte SHA-256 seulement)
            $table->string('token_hash', 64)->unique();
            // Code du message WhatsApp prérempli : connu du navigateur, c'est l'expéditeur qui prouve le numéro
            $table->string('whatsapp_code', 6);
            // Code envoyé par SMS : secret
            $table->string('sms_code_hash')->nullable();
            $table->unsignedTinyInteger('sms_count')->default(0);
            $table->timestamp('sms_sent_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Informations de l'inscription en attente (chiffrées ; mot de passe déjà haché)
            $table->text('payload')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('verified_via', 10)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['phone', 'created_at']);
            $table->index(['company_id', 'whatsapp_code']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('merchant_signup')->default(true)->after('whatsapp_orders');
        });

        // Origine du compte marchand : back-office ou inscription en ligne
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('source', 20)->default('backoffice')->after('status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('phone_verified_at'));
        Schema::table('merchants', fn (Blueprint $table) => $table->dropColumn('source'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('merchant_signup'));
        Schema::dropIfExists('phone_verifications');
    }
};
