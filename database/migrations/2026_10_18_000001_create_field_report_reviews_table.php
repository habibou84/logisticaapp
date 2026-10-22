<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Suivi des remontées terrain (notes et problèmes des livreurs) : qui l'a traitée, quand, comment.
        // Le journal des courses (order_events) reste immuable.
        Schema::create('field_report_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_event_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('comment', 500)->nullable();
            $table->timestamp('handled_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_report_reviews');
    }
};
