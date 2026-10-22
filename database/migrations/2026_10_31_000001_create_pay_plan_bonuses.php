<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paie des livreurs, phase 3 : primes d'objectifs par période de paie (paliers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_plan_bonuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_plan_id')->constrained()->cascadeOnDelete();
            // deliveries | pickups | success_rate | worked_days
            $table->string('metric', 20);
            // Seuil à atteindre (nombre, ou % pour le taux de réussite)
            $table->unsignedInteger('threshold');
            // Taux de réussite : nombre minimal de livraisons tentées pour y avoir droit
            $table->unsignedInteger('min_count')->nullable();
            $table->unsignedInteger('amount');
            $table->string('label', 100)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_plan_bonuses');
    }
};
