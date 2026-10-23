<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppléments des clients sous contrat (distribution, MAD) : demandes exceptionnelles ajoutées à la facture
 * du mois, à part du forfait (livraison urgente un dimanche, mise sous pli…). Figés à l'émission de la facture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_supplements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('activity', 20);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            // Facture émise qui l'a repris : le supplément ne change plus
            $table->foreignId('client_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->date('performed_on');
            $table->string('label');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'merchant_id', 'activity', 'performed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_supplements');
    }
};
