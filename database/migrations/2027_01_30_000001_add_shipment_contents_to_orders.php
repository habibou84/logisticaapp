<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envois remis ensemble par le client :
 *  - MAD : une seule course peut contenir chèques, plis et colis (shipment_contents) ;
 *  - distribution : une course par type, reliées par shipment_group (même saisie).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->json('shipment_contents')->nullable()->after('shipment_quantity');
            $table->string('shipment_group', 36)->nullable()->after('shipment_contents')->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['shipment_group']);
            $table->dropColumn(['shipment_contents', 'shipment_group']);
        });
    }
};
