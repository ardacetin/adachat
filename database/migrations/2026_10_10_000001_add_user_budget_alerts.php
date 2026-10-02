<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Budget alerts for users: when each period's 80 % and 100 % e-mail went
 * out (at most once per threshold), and whether the user wants them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_periods', function (Blueprint $table) {
            $table->dateTime('alerted_80_at')->nullable()->after('reserved_usd');
            $table->dateTime('alerted_100_at')->nullable()->after('alerted_80_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('budget_emails')->default(true)->after('appearance');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('budget_emails');
        });

        Schema::table('budget_periods', function (Blueprint $table) {
            $table->dropColumn(['alerted_80_at', 'alerted_100_at']);
        });
    }
};
