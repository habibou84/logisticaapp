<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sessions d'assistance : le super administrateur ouvre l'espace d'une entreprise
 * avec le compte d'un de ses utilisateurs. Chaque ouverture est tracée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Super administrateur qui ouvre la session
            $table->foreignId('opened_by')->constrained('users')->cascadeOnDelete();
            // Compte utilisé dans l'entreprise
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_sessions');
    }
};
