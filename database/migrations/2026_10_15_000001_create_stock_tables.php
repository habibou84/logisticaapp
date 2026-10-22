<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Entrepôts de l'entreprise : y sont stockés les produits des marchands
        Schema::create('hubs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('zone_id')->constrained('zones');
            $table->text('address')->nullable();
            $table->string('landmark')->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained();
            $table->string('sku', 60)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('price')->default(0);
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->unsignedInteger('low_stock_threshold')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'sku']);
            $table->index(['company_id', 'merchant_id']);
        });

        // Où se trouve le stock d'un marchand : chez lui (hub_id null) ou dans un entrepôt
        Schema::create('stock_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained();
            $table->foreignId('hub_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index(['merchant_id', 'hub_id']);
        });

        // Cache des quantités, recalculable depuis stock_movements
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_location_id')->constrained()->cascadeOnDelete();
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0); // réservé par des courses non livrées
            $table->timestamps();

            $table->unique(['product_id', 'stock_location_id']);
        });

        // Journal immuable des mouvements
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_location_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // receipt | withdrawal | adjustment | reservation | release | shipment
            $table->integer('on_hand_change')->default(0);
            $table->integer('reserved_change')->default(0);
            $table->integer('on_hand_after');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
            $table->index(['stock_location_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // Commande préparée dans un entrepôt : pas de ramassage chez le marchand
            $table->foreignId('pickup_hub_id')->nullable()->after('pickup_zone_id')->constrained('hubs')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable()->after('confirmed_at');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete(); // null = article libre
            $table->foreignId('stock_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price')->default(0);
            $table->string('stock_state', 20)->nullable(); // reserved | shipped | released
            $table->timestamps();
        });

        Schema::create('storage_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained();
            $table->foreignId('hub_id')->nullable()->constrained()->nullOnDelete(); // null = tous les entrepôts
            $table->string('billing_type', 20); // free | monthly_flat | per_unit_day | per_order
            $table->unsignedInteger('price')->default(0);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'merchant_id']);
        });

        // Une facturation par contrat et par mois (évite les doublons)
        Schema::create('storage_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained();
            $table->foreignId('storage_contract_id')->constrained()->cascadeOnDelete();
            $table->date('period'); // premier jour du mois facturé
            $table->unsignedInteger('quantity')->default(0); // unités-jours, courses préparées ou 1 (forfait)
            $table->unsignedInteger('amount');
            $table->foreignId('ledger_entry_id')->nullable()->constrained('merchant_ledger_entries')->nullOnDelete();
            $table->timestamps();

            $table->unique(['storage_contract_id', 'period']);
        });

        Schema::table('merchant_payouts', function (Blueprint $table) {
            $table->integer('total_storage_fees')->default(0)->after('total_other_fees');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_payouts', fn (Blueprint $table) => $table->dropColumn('total_storage_fees'));
        Schema::dropIfExists('storage_charges');
        Schema::dropIfExists('storage_contracts');
        Schema::dropIfExists('order_items');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pickup_hub_id');
            $table->dropColumn('prepared_at');
        });
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('stock_locations');
        Schema::dropIfExists('products');
        Schema::dropIfExists('hubs');
    }
};
