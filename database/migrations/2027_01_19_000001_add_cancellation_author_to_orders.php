<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Annulations : qui a annulé (client ou équipe, quelle personne) et pourquoi (motif obligatoire d'une liste). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('cancel_reason_code', 40)->nullable();
            // merchant : le client ; company : l'équipe de l'entreprise
            $table->string('cancelled_by_type', 10)->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by_id');
            $table->dropColumn(['cancel_reason_code', 'cancelled_by_type']);
        });
    }
};
