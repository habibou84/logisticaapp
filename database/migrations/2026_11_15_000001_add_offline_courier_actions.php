<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Mode hors connexion des livreurs : réglage de l'entreprise, actions envoyées au retour du réseau
 * (une seule fois chacune, avec leur résultat) et livraisons dont le code client est à vérifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('offline_courier_actions')->default(false);
        });

        Schema::table('orders', function (Blueprint $table) {
            // Livrée hors connexion avec un code client faux : à vérifier par l'entreprise
            $table->timestamp('delivery_code_unverified_at')->nullable();
        });

        Schema::create('offline_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Identifiant tiré par le téléphone : une action renvoyée deux fois n'est appliquée qu'une fois
            $table->string('client_id', 64);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->text('message')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_id']);
            $table->index(['company_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_actions');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('delivery_code_unverified_at'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('offline_courier_actions'));
    }
};
