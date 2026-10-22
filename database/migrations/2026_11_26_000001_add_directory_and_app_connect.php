<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Annuaire des entreprises de livraison (jibiat.com/annuaire, Jibiat Marchand) et connexion en un clic :
 * - l'entreprise choisit d'y figurer et se présente en une phrase ;
 * - codes d'autorisation à usage unique (PKCE) échangés contre une clé d'API ;
 * - demandes d'ouverture de compte des e-commerçants quand les inscriptions sont fermées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('directory_listed')->default(false);
            $table->string('directory_pitch', 240)->nullable();
        });

        Schema::create('connect_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('client_id', 60);
            $table->text('redirect_uri');
            $table->string('code_hash', 64)->unique();
            $table->string('code_challenge', 128);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('connect_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('client_id', 60)->nullable();
            $table->string('business_name');
            $table->string('contact_name');
            $table->string('phone', 30);
            $table->string('email')->nullable();
            $table->foreignId('pickup_zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->string('pickup_address', 500)->nullable();
            $table->string('message', 500)->nullable();
            // Mot de passe choisi par l'e-commerçant, haché ; effacé une fois la demande traitée
            $table->string('password_hash')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connect_requests');
        Schema::dropIfExists('connect_codes');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['directory_listed', 'directory_pitch']);
        });
    }
};
