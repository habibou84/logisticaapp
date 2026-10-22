<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Exiger le code de livraison du destinataire pour valider une livraison
            $table->boolean('require_delivery_code')->default(false)->after('default_max_attempts');
        });

        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('business_name');
            $table->string('contact_name')->nullable();
            $table->string('phone', 20);
            $table->string('whatsapp_phone', 20)->nullable()->index();
            $table->string('email')->nullable();
            // Adresse de ramassage par défaut
            $table->foreignId('pickup_zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->text('pickup_address')->nullable();
            $table->string('pickup_landmark')->nullable();
            $table->decimal('pickup_lat', 10, 7)->nullable();
            $table->decimal('pickup_lng', 10, 7)->nullable();
            // Grille négociée ; null = grille par défaut de l'entreprise
            $table->foreignId('pricing_grid_id')->nullable()->constrained()->nullOnDelete();
            $table->string('default_fee_payer', 20)->default('merchant'); // merchant | recipient
            $table->string('status', 20)->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'phone']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('merchant_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
        });

        Schema::create('couriers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('vehicle_type', 20)->default('moto'); // moto | velo | voiture | tricycle | pieton
            $table->string('vehicle_plate', 30)->nullable();
            $table->boolean('is_available')->default(false);
            $table->decimal('current_lat', 10, 7)->nullable();
            $table->decimal('current_lng', 10, 7)->nullable();
            $table->timestamp('last_location_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('courier_zone', function (Blueprint $table) {
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained()->cascadeOnDelete();
            $table->primary(['courier_id', 'zone_id']);
        });

        Schema::create('recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('phone', 20);
            $table->string('phone2', 20)->nullable();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->text('address')->nullable();
            $table->string('landmark')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedInteger('deliveries_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamps();

            $table->unique(['merchant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipients');
        Schema::dropIfExists('courier_zone');
        Schema::dropIfExists('couriers');
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('merchant_id'));
        Schema::dropIfExists('merchants');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('require_delivery_code'));
    }
};
