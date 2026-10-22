<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Point de la semaine envoyé aux administrateurs de l'entreprise le lundi matin
 * (WhatsApp et cloche) : activé par défaut, date du dernier envoi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('weekly_report')->default(true)->after('merchant_signup');
            $table->timestamp('weekly_report_sent_at')->nullable()->after('weekly_report');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['weekly_report', 'weekly_report_sent_at']);
        });
    }
};
