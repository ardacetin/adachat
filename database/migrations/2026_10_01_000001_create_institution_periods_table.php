<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The institution's spending per month: the sum of all users' periods,
     * kept by the budget engine under a row lock so that an institution-wide
     * cap (InstitutionSettings::monthly_cap_usd) can never be exceeded.
     */
    public function up(): void
    {
        Schema::create('institution_periods', function (Blueprint $table) {
            $table->id();
            // Same half-open UTC month as the users' budget_periods.
            $table->dateTime('period_start')->unique();
            $table->dateTime('period_end');
            $table->decimal('spent_usd', 20, 10)->default(0);
            $table->decimal('reserved_usd', 20, 10)->default(0);
            // When the 80 % and 100 % alerts were sent for this month.
            $table->dateTime('alerted_80_at')->nullable();
            $table->dateTime('alerted_100_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        DB::statement('ALTER TABLE institution_periods ADD CONSTRAINT institution_periods_amounts_check CHECK (
            spent_usd >= 0 AND reserved_usd >= 0 AND period_end > period_start
        )');

        // Existing installations: start from what the users' periods hold.
        DB::statement(<<<'SQL'
            INSERT INTO institution_periods (period_start, period_end, spent_usd, reserved_usd, created_at, updated_at)
            SELECT period_start, MAX(period_end), SUM(spent_usd), SUM(reserved_usd), UTC_TIMESTAMP(), UTC_TIMESTAMP()
            FROM budget_periods
            GROUP BY period_start
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_periods');
    }
};
