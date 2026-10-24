<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Événements terrain signalés par les livreurs (panne, accident, contrôle…), hors d'une course précise :
 * courses touchées, photos et notes vocales, échanges avec le dispatch, prise en charge et résolution,
 * effets appliqués (pause, courses remises, clients prévenus) ; réglages de l'entreprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            // Identifiant tiré par le téléphone : un signalement renvoyé (hors connexion) n'est créé qu'une fois
            $table->string('client_uuid', 64)->nullable();
            // Type (clé des réglages), avec son libellé et son icône au moment du signalement
            $table->string('type', 40);
            $table->string('type_label', 80);
            $table->string('icon', 16)->nullable();
            // info | hindrance | blocking | urgent
            $table->string('severity', 12);
            // continue | blocked | stopped (je peux continuer, bloqué un moment, j'arrête pour aujourd'hui)
            $table->string('capacity', 12)->default('continue');
            $table->timestamp('blocked_until')->nullable();
            $table->text('note')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            // Coût de dépannage indiqué par le livreur (réparation, essence…)
            $table->unsignedInteger('cost')->nullable();
            // open | taken | resolved
            $table->string('status', 12)->default('open');
            $table->foreignId('taken_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('taken_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            // Le livreur a repris (« C'est réglé »)
            $table->timestamp('resumed_at')->nullable();
            // Retards des courses touchées excusés (note automatique, primes)
            $table->boolean('excused')->default(false);
            // Effets appliqués : pause du livreur, courses remises, absence au planning, clients prévenus
            $table->json('effects')->nullable();
            $table->unsignedTinyInteger('reminders')->default(0);
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();

            $table->unique(['courier_id', 'client_uuid']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('field_event_order', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->unique(['field_event_id', 'order_id']);
        });

        Schema::create('field_event_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_event_id')->constrained()->cascadeOnDelete();
            // photo | voice
            $table->string('kind', 10);
            $table->string('disk', 20)->default('local');
            $table->string('path');
            $table->string('mime_type', 80)->nullable();
            $table->unsignedSmallInteger('duration')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('field_event_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('from_courier')->default(false);
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->json('field_event_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('field_event_settings'));
        Schema::dropIfExists('field_event_messages');
        Schema::dropIfExists('field_event_files');
        Schema::dropIfExists('field_event_order');
        Schema::dropIfExists('field_events');
    }
};
