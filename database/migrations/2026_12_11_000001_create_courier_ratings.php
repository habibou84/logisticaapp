<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notation des livreurs : notes des clients (course, mise à disposition), de l'équipe (course,
 * évaluation périodique) et des destinataires ; livreurs préférés ou écartés par un client ;
 * réglages de l'entreprise (poids, seuils, invitations, usages).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            // client | team | recipient
            $table->string('source', 20);
            // course | period (mise à disposition, évaluation de l'équipe)
            $table->string('scope', 20)->default('course');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('stars');
            $table->json('criteria')->nullable();
            $table->json('reasons')->nullable();
            $table->text('comment')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            // Le livreur avait signalé un problème sur la course : la note compte moins
            $table->decimal('weight', 3, 2)->default(1);
            $table->timestamp('neutralized_at')->nullable();
            $table->foreignId('neutralized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('neutralized_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'courier_id', 'created_at']);
            $table->index(['order_id', 'source']);
        });

        Schema::create('merchant_courier_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            // preferred | excluded
            $table->string('kind', 20);
            $table->timestamps();

            $table->unique(['merchant_id', 'courier_id']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->json('rating_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('rating_settings'));
        Schema::dropIfExists('merchant_courier_preferences');
        Schema::dropIfExists('courier_ratings');
    }
};
