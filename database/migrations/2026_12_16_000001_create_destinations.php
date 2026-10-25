<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Annuaire des destinations (distribution, MAD), partagé par tous les clients : entreprises et administrations
 * où l'on livre, proposées pendant la saisie d'une course pour remplir la fiche. Il s'enrichit à chaque course ;
 * un numéro douteux est gardé tel quel, « à vérifier », jusqu'à ce qu'un bon numéro soit saisi. Le livreur
 * confirme la position GPS sur place ; elle sert aux courses suivantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            // Sigle (« ITWA ») et nom en clair
            $table->string('acronym', 60)->nullable();
            $table->string('city', 80)->nullable();
            // Commune (zone) et quartier
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('district', 120)->nullable();
            $table->text('address')->nullable();
            $table->string('landmark', 255)->nullable();
            // Numéros vérifiés (E.164) ; les numéros d'origine douteux restent dans phones_raw, « à vérifier »
            $table->string('phone', 20)->nullable();
            $table->string('phone2', 20)->nullable();
            $table->string('phone3', 20)->nullable();
            $table->string('phones_raw', 255)->nullable();
            $table->boolean('phone_check')->default(false);
            $table->string('email', 190)->nullable();
            $table->string('sector', 120)->nullable();
            // Texte de recherche (nom, sigle, quartier, adresse, numéros), sans accents ni majuscules
            $table->text('search')->nullable();
            // Position confirmée : par un livreur sur place, par l'équipe, ou d'après une course
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('location_accuracy')->nullable();
            $table->string('location_source', 12)->nullable();
            $table->timestamp('located_at')->nullable();
            $table->foreignId('located_by')->nullable()->constrained('users')->nullOnDelete();
            // Positions qui ne concordent pas : à trancher par l'équipe
            $table->boolean('location_conflict')->default(false);
            $table->unsignedInteger('deliveries_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            // import | order | staff
            $table->string('source', 10)->default('staff');
            // active | pending (ajoutée par une course, à valider)
            $table->string('status', 10)->default('active');
            // Identifiant dans l'ancienne base (import)
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'phone']);
            $table->unique(['company_id', 'legacy_id']);
        });

        // Positions relevées sur place (historique, conflits)
        Schema::create('destination_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->unsignedSmallInteger('accuracy')->nullable();
            // Distance à la position déjà connue (m)
            $table->unsignedInteger('distance')->nullable();
            // kept (gardée), replaced (a remplacé l'ancienne), conflict (à trancher), rejected
            $table->string('outcome', 10);
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('destination_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('companies', fn (Blueprint $table) => $table->json('destination_settings')->nullable());
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('destination_settings'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('destination_id'));
        Schema::dropIfExists('destination_locations');
        Schema::dropIfExists('destinations');
    }
};
