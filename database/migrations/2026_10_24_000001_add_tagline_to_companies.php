<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Accroche affichée sur la page d'accueil des e-commerçants (le logo utilise logo_path)
        Schema::table('companies', function (Blueprint $table) {
            $table->string('tagline', 160)->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('tagline'));
    }
};
