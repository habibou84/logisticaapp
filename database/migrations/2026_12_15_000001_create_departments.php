<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Départements des clients (distribution, MAD) : une banque et ses départements Finance, Marketing, Gestion…
 * Chacun est connu de l'application (responsable, emplacement, adresse de ramassage s'il est sur un autre site,
 * centre de coûts, quota du mois) ; les comptes des employés y sont rattachés ; chaque course dit quel
 * département l'a demandée et, en courrier interne, à quel département elle va ; qui a remis et qui a reçu
 * est noté ; la facture se ventile par département. Réglages de l'entreprise, changés client par client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // Code court affiché partout (FIN, MKT)
            $table->string('code', 12)->nullable();
            $table->string('manager_name', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 190)->nullable();
            // Bâtiment, étage, bureau : où le livreur trouve le département
            $table->string('location', 255)->nullable();
            // Autre site que le client : sa propre adresse de ramassage et de remise (vide : celle du client)
            $table->foreignId('pickup_zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->string('pickup_address', 500)->nullable();
            $table->string('pickup_landmark', 255)->nullable();
            $table->decimal('pickup_lat', 10, 7)->nullable();
            $table->decimal('pickup_lng', 10, 7)->nullable();
            $table->string('cost_center', 60)->nullable();
            // Envois du mois prévus (vide : sans quota)
            $table->unsignedInteger('monthly_quota')->nullable();
            // Département principal : paie le forfait quand il n'y a rien à répartir, les lots et suppléments
            $table->boolean('is_main')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('notes', 1000)->nullable();
            // Mois et seuil du dernier avertissement de quota (« 2026-11:80 »)
            $table->string('quota_alert', 12)->nullable();
            $table->timestamps();
            $table->index(['merchant_id', 'active']);
        });

        Schema::create('department_user', function (Blueprint $table) {
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['department_id', 'user_id']);
        });

        Schema::table('companies', fn (Blueprint $table) => $table->json('department_settings')->nullable());
        Schema::table('merchants', fn (Blueprint $table) => $table->json('department_settings')->nullable());

        Schema::table('orders', function (Blueprint $table) {
            // Département qui envoie, et département destinataire (courrier interne)
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('to_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('requested_by', 120)->nullable();
            // Qui a remis la course au livreur, et la fonction de la personne qui l'a reçue
            $table->string('handed_by_name', 120)->nullable();
            $table->string('handed_by_role', 80)->nullable();
            $table->string('received_by_role', 80)->nullable();
            $table->index(['department_id', 'created_at']);
        });

        // Bordereaux : ce qu'un département a remis ou reçu un jour, signé par la personne
        Schema::create('department_slips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            // pickup (remis au livreur) | delivery (reçu du livreur)
            $table->string('kind', 10);
            $table->date('day');
            $table->json('order_ids');
            $table->string('signed_by_name', 120)->nullable();
            $table->string('signed_by_role', 80)->nullable();
            $table->string('signature_path')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['department_id', 'day']);
        });

        // Facture ventilée (lignes par département) ou une facture par département
        Schema::table('client_invoices', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->dropUnique(['merchant_id', 'period', 'activity']);
            $table->unique(['merchant_id', 'period', 'activity', 'department_id']);
        });
        Schema::table('client_invoice_lines', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_invoice_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('department_id'));
        Schema::table('client_invoices', function (Blueprint $table) {
            $table->dropUnique(['merchant_id', 'period', 'activity', 'department_id']);
            $table->dropConstrainedForeignId('department_id');
            $table->unique(['merchant_id', 'period', 'activity']);
        });
        Schema::dropIfExists('department_slips');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['department_id', 'created_at']);
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('to_department_id');
            $table->dropColumn(['requested_by', 'handed_by_name', 'handed_by_role', 'received_by_role']);
        });
        Schema::table('merchants', fn (Blueprint $table) => $table->dropColumn('department_settings'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('department_settings'));
        Schema::dropIfExists('department_user');
        Schema::dropIfExists('departments');
    }
};
