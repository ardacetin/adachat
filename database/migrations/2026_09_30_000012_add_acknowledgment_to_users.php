<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which version of the usage notice each user acknowledged, and when.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('acknowledged_version')->nullable()->after('last_active_at');
            $table->dateTime('acknowledged_at')->nullable()->after('acknowledged_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['acknowledged_version', 'acknowledged_at']);
        });
    }
};
