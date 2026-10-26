<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facture normalisée électronique (FNE, DGI) : réglages de l'entreprise, modèle du client, certification des
 * factures et des avoirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('fne_settings')->nullable();
            // Clé API de l'espace FNE (onglet Paramétrage), chiffrée
            $table->text('fne_api_key')->nullable();
            // Stickers restants et alerte de stock, d'après la dernière réponse
            $table->integer('fne_balance_sticker')->nullable();
            $table->boolean('fne_warning')->default(false);
        });

        Schema::table('merchants', function (Blueprint $table) {
            // B2B (NCC), B2C, B2G ; vide : B2B s'il a un NCC, sinon B2C
            $table->string('fne_template', 3)->nullable();
            // Exonération : conventionnelle (TVAC) ou légale (TVAD)
            $table->string('vat_exemption_type', 12)->default('conventional');
        });

        foreach (['client_invoices', 'client_credit_notes'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                // pending (à certifier, relancée), certified, failed (refusée : données à corriger)
                $table->string('fne_status', 10)->nullable()->index();
                $table->string('fne_reference', 40)->nullable();
                // Adresse de vérification (QR code)
                $table->string('fne_token', 255)->nullable();
                $table->string('fne_id', 64)->nullable();
                $table->string('fne_error', 500)->nullable();
                $table->timestamp('fne_certified_at')->nullable();
                $table->unsignedSmallInteger('fne_attempts')->default(0);
                if ($name === 'client_invoices') {
                    // Articles de la FNE : ligne de la facture => identifiant FNE (pour les avoirs)
                    $table->json('fne_items')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['client_invoices', 'client_credit_notes'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(array_merge(
                ['fne_status', 'fne_reference', 'fne_token', 'fne_id', 'fne_error', 'fne_certified_at', 'fne_attempts'],
                $name === 'client_invoices' ? ['fne_items'] : [],
            )));
        }
        Schema::table('merchants', fn (Blueprint $table) => $table->dropColumn(['fne_template', 'vat_exemption_type']));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['fne_settings', 'fne_api_key', 'fne_balance_sticker', 'fne_warning']));
    }
};
