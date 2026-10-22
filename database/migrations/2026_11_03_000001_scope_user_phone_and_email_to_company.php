<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plateforme multi-entreprises : un même numéro (ou e-mail) peut avoir un compte dans
 * chaque entreprise (livreur de deux entreprises, marchand client de deux livreurs).
 * L'unicité devient propre à l'entreprise ; celle des super administrateurs (sans
 * entreprise) est vérifiée par l'application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropUnique(['email']);
            $table->unique(['company_id', 'phone']);
            $table->unique(['company_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'phone']);
            $table->dropUnique(['company_id', 'email']);
            $table->unique('phone');
            $table->unique('email');
        });
    }
};
