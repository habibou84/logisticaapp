<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Course envoyée par une application qui prévient elle-même l'équipe du marchand (Jibiat Marchand) :
     * pas d'alerte en double dans la cloche du marchand.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('merchant_alerts_muted')->default(false)->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('merchant_alerts_muted');
        });
    }
};
