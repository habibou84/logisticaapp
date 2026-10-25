<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guide vidéo « Bien démarrer » : affiché ou non sur le tableau de bord (réglage de l'entreprise), masqué par un
 * administrateur, chapitres déjà regardés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('guide_videos')->default(true);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('guide_videos_hidden_at')->nullable();
            $table->json('guide_videos_watched')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('guide_videos'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['guide_videos_hidden_at', 'guide_videos_watched']));
    }
};
