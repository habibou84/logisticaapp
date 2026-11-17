<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atelier : profil des mécaniciens (en service, position), interventions sur les pannes (attribution, étapes
 * horodatées, diagnostic, clôture), photos avant et après, achats de pièces sur place soumis à l'accord de
 * l'administrateur ; réglages de l'atelier sur l'entreprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('workshop_settings')->nullable();
        });

        Schema::create('mechanics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_available')->default(false);
            $table->timestamp('available_since')->nullable();
            $table->decimal('current_lat', 10, 7)->nullable();
            $table->decimal('current_lng', 10, 7)->nullable();
            $table->timestamp('last_location_at')->nullable();
            $table->timestamps();
        });

        Schema::create('repairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mechanic_id')->nullable()->constrained()->nullOnDelete();
            // Origine : signalement du livreur, ou intervention créée par l'équipe
            $table->string('source', 20)->default('field_event');
            $table->string('title', 160);
            $table->string('icon', 16)->nullable();
            $table->string('severity', 12)->default('blocking');
            $table->text('note')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            // searching, offered, accepted, en_route, on_site, repairing, done, cancelled
            $table->string('status', 20);
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('offer_expires_at')->nullable();
            // Mécaniciens qui ont refusé ou laissé passer la proposition
            $table->json('declined')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->decimal('arrival_lat', 10, 7)->nullable();
            $table->decimal('arrival_lng', 10, 7)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            // Diagnostic
            $table->string('category', 40)->nullable();
            $table->string('category_label', 60)->nullable();
            $table->string('part', 120)->nullable();
            $table->text('diagnosis')->nullable();
            $table->string('decision', 20)->nullable();
            // Clôture
            $table->string('cause', 20)->nullable();
            $table->json('parts')->nullable();
            $table->unsignedInteger('parts_amount')->default(0);
            $table->string('garage', 120)->nullable();
            $table->unsignedInteger('garage_amount')->nullable();
            $table->unsignedInteger('odometer')->nullable();
            $table->text('remark')->nullable();
            $table->boolean('courier_can_resume')->default(false);
            $table->foreignId('vehicle_log_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['mechanic_id', 'status']);
        });

        Schema::create('repair_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repair_id')->constrained()->cascadeOnDelete();
            // before, after, receipt
            $table->string('kind', 12);
            $table->string('disk', 20)->default('local');
            $table->string('path');
            $table->string('mime_type', 100)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('repair_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repair_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mechanic_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 120);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedInteger('estimated_amount');
            $table->string('reason', 255)->nullable();
            // pending, approved, refused
            $table->string('status', 12)->default('pending');
            $table->unsignedInteger('max_amount')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            // Saisi par le mécanicien après l'achat
            $table->unsignedInteger('paid_amount')->nullable();
            $table->string('receipt_path')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_purchases');
        Schema::dropIfExists('repair_files');
        Schema::dropIfExists('repairs');
        Schema::dropIfExists('mechanics');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('workshop_settings'));
    }
};
