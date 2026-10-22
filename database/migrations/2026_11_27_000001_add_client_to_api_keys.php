<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Application reliée par la connexion en un clic (ex. jibiat-marchand) : sa clé marche quelle que soit la formule
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('client', 40)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('client');
        });
    }
};
