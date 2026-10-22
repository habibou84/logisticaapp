<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Domaine personnalisé d'une entreprise (ex. livraison.rapide-express.ci) : en plus de
 * {slug}.jibiat.com. « active » une fois vérifié (DNS et HTTPS) ; les liens envoyés
 * (WhatsApp, SMS) l'utilisent alors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('custom_domain', 253)->nullable()->unique()->after('slug');
            $table->string('custom_domain_status', 20)->nullable()->after('custom_domain'); // pending | active
            $table->string('custom_domain_error', 255)->nullable()->after('custom_domain_status');
            $table->timestamp('custom_domain_checked_at')->nullable()->after('custom_domain_error');
            // Nom d'hôte créé chez Cloudflare for SaaS (si la création automatique est configurée)
            $table->string('cloudflare_hostname_id', 64)->nullable()->after('custom_domain_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['custom_domain']);
            $table->dropColumn(['custom_domain', 'custom_domain_status', 'custom_domain_error', 'custom_domain_checked_at', 'cloudflare_hostname_id']);
        });
    }
};
