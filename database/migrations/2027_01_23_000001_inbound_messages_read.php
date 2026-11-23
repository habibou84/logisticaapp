<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages › Reçus : messages WhatsApp lus ou non par l'équipe ; messages du simulateur à part.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->after('processed_at');
            $table->foreignId('read_by')->nullable()->after('read_at')->constrained('users')->nullOnDelete();
            $table->boolean('simulated')->default(false)->after('read_by');
            $table->index(['company_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'read_at']);
            $table->dropConstrainedForeignId('read_by');
            $table->dropColumn(['read_at', 'simulated']);
        });
    }
};
