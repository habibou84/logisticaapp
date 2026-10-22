<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_reasons', function (Blueprint $table) {
            $table->id();
            // null = motif commun à toutes les entreprises
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('label');
            $table->string('applies_to', 20)->default('delivery'); // pickup | delivery | both
            $table->boolean('requires_date')->default(false);
            $table->boolean('counts_as_attempt')->default(true);
            $table->boolean('triggers_return')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained();
            $table->string('tracking_code', 20)->unique();
            $table->string('merchant_reference')->nullable();
            $table->string('source', 20)->default('dashboard');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Ramassage (copie figée à la création)
            $table->foreignId('pickup_zone_id')->constrained('zones');
            $table->text('pickup_address')->nullable();
            $table->string('pickup_landmark')->nullable();
            $table->string('pickup_contact_name')->nullable();
            $table->string('pickup_phone', 20)->nullable();
            $table->decimal('pickup_lat', 10, 7)->nullable();
            $table->decimal('pickup_lng', 10, 7)->nullable();

            // Destinataire
            $table->foreignId('recipient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone', 20);
            $table->string('recipient_phone2', 20)->nullable();
            $table->foreignId('delivery_zone_id')->constrained('zones');
            $table->text('delivery_address')->nullable();
            $table->string('delivery_landmark')->nullable();
            $table->decimal('delivery_lat', 10, 7)->nullable();
            $table->decimal('delivery_lng', 10, 7)->nullable();
            $table->date('delivery_scheduled_date')->nullable();
            $table->string('delivery_time_slot', 20)->nullable();

            // Colis
            $table->text('description')->nullable();
            $table->string('package_size', 5)->nullable(); // S | M | L | XL
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->boolean('is_fragile')->default(false);
            $table->boolean('is_express')->default(false);
            $table->text('merchant_note')->nullable();

            // Argent (montants en FCFA, figés à la création)
            $table->unsignedInteger('delivery_fee');
            $table->unsignedInteger('surcharges_total')->default(0);
            $table->json('pricing_details')->nullable();
            $table->string('fee_payer', 20)->default('merchant'); // merchant | recipient
            $table->unsignedInteger('items_amount')->default(0);
            $table->unsignedInteger('cod_amount')->default(0);
            $table->unsignedInteger('collected_amount')->nullable();

            // Suivi
            $table->string('status', 30)->default('pending');
            $table->foreignId('pickup_courier_id')->nullable()->constrained('couriers')->nullOnDelete();
            $table->foreignId('delivery_courier_id')->nullable()->constrained('couriers')->nullOnDelete();
            $table->foreignId('return_courier_id')->nullable()->constrained('couriers')->nullOnDelete();
            $table->unsignedSmallInteger('attempts_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->foreignId('last_incident_reason_id')->nullable()->constrained('incident_reasons')->nullOnDelete();
            $table->text('delivery_code')->nullable(); // chiffré
            $table->boolean('return_requested')->default(false);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'created_at']);
            $table->index(['merchant_id', 'created_at']);
            $table->index(['merchant_id', 'status']);
            $table->index(['pickup_courier_id', 'status']);
            $table->index(['delivery_courier_id', 'status']);
            $table->index('recipient_phone');
        });

        Schema::create('order_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained();
            $table->string('type', 20); // pickup | delivery | return
            $table->string('status', 20)->default('assigned'); // assigned | accepted | refused | in_progress | completed | failed | cancelled
            $table->string('order_status_before', 30)->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('refusal_reason')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'type', 'status']);
            $table->index(['courier_id', 'status']);
        });

        // Journal immuable : insertions uniquement
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('order_assignments')->nullOnDelete();
            $table->string('type', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->foreignId('incident_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->date('rescheduled_to')->nullable();
            $table->text('note')->nullable();
            $table->string('actor_type', 20)->default('user'); // user | system | whatsapp | api
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_role', 30)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->json('meta')->nullable();
            $table->boolean('visible_to_merchant')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });

        Schema::create('order_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('order_events')->nullOnDelete();
            $table->string('type', 30); // photo_pickup | photo_delivery | photo_incident | document
            $table->string('disk', 20)->default('local');
            $table->string('path');
            $table->string('mime_type', 100)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_attachments');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_assignments');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('incident_reasons');
    }
};
