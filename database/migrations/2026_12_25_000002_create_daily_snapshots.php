<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relevé du soir (Statistiques › Délais et échecs) : courses non traitées, délai interne dépassé, sans livreur…
 * pour suivre leur évolution dans le temps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->json('data');
            $table->timestamps();

            $table->unique(['company_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_snapshots');
    }
};
