<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Apparence des espaces (e-commerçant, livreur, équipe) : couleurs, noms d'application, icônes
        Schema::table('companies', function (Blueprint $table) {
            $table->json('appearance')->nullable()->after('tagline');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('appearance'));
    }
};
