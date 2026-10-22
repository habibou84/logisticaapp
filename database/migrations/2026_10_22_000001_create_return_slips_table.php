<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bon de retour : colis d'un marchand rapportés ensemble par un livreur, remise signée ou photographiée
        Schema::create('return_slips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 30)->unique();
            $table->string('status', 20)->default('open'); // open, handed, cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handed_at')->nullable();
            $table->string('received_by_name', 120)->nullable();
            $table->string('signature_path')->nullable();
            $table->string('photo_path')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['courier_id', 'status']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('return_slip_id')->nullable()->after('return_requested')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('return_slip_id'));
        Schema::dropIfExists('return_slips');
    }
};
