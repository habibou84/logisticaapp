<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Commune -> quartier (ex. Cocody -> Angré)
            $table->foreignId('parent_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->string('name');
            $table->string('city')->default('Abidjan');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });

        Schema::create('pricing_grids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'is_default']);
        });

        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pricing_grid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('origin_zone_id')->constrained('zones')->cascadeOnDelete();
            $table->foreignId('destination_zone_id')->constrained('zones')->cascadeOnDelete();
            $table->unsignedInteger('price');
            // S'applique aussi dans le sens inverse (Yopougon -> Cocody)
            $table->boolean('is_symmetric')->default(true);
            $table->timestamps();

            $table->unique(['pricing_grid_id', 'origin_zone_id', 'destination_zone_id'], 'pricing_rules_route_unique');
        });

        Schema::create('pricing_surcharges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pricing_grid_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // express | fragile | weight
            $table->decimal('min_value', 8, 2)->nullable();
            $table->decimal('max_value', 8, 2)->nullable();
            $table->unsignedInteger('amount')->default(0);
            $table->decimal('percent', 5, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_surcharges');
        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('pricing_grids');
        Schema::dropIfExists('zones');
    }
};
