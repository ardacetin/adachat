<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pinned conversations stay at the top of the sidebar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dateTime('pinned_at')->nullable()->after('last_message_at');
            $table->index(['user_id', 'pinned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'pinned_at']);
            $table->dropColumn('pinned_at');
        });
    }
};
