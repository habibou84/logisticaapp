<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Messages aux e-commerçants : notification sur leur téléphone d'abord (gratuit), WhatsApp à défaut
            $table->boolean('merchant_push_first')->default(true)->after('sms_fallback');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('merchant_push_first');
        });
    }
};
