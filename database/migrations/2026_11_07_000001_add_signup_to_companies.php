<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Inscription des entreprises en libre-service : origine de l'entreprise (créée dans la
 * console, ou inscrite en ligne) et adresse IP de l'inscription (contre les abus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('status');
            $table->string('signup_ip', 45)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['source', 'signup_ip']);
        });
    }
};
