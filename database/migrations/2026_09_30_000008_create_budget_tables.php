<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Budget engine: policies, per-user monthly periods, reservations for
     * in-flight requests and the append-only usage ledger. Money is
     * DECIMAL(20,10) USD; token prices are DECIMAL(14,6) per million.
     */
    public function up(): void
    {
        Schema::create('budget_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('monthly_limit_usd', 20, 10);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        DB::statement('ALTER TABLE budget_policies ADD CONSTRAINT budget_policies_limit_check CHECK (monthly_limit_usd >= 0)');

        $now = now();
        $defaultPolicyId = DB::table('budget_policies')->insertGetId([
            'name' => 'Default',
            'monthly_limit_usd' => (string) config('ada.budget.default_monthly_limit_usd'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('budget_policy_id')->nullable()->after('description');
            $table->unsignedSmallInteger('requests_per_minute')->default(20)->after('budget_policy_id');
            $table->unsignedTinyInteger('max_concurrent_streams')->default(2)->after('requests_per_minute');
        });

        DB::table('groups')->update(['budget_policy_id' => $defaultPolicyId]);

        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('budget_policy_id')->nullable(false)->change();
            $table->foreign('budget_policy_id')->references('id')->on('budget_policies')->restrictOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->decimal('monthly_limit_override_usd', 20, 10)->nullable()->after('group_id');
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_limit_override_check CHECK (monthly_limit_override_usd IS NULL OR monthly_limit_override_usd >= 0)');

        Schema::create('budget_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // Half-open [period_start, period_end), UTC.
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->decimal('limit_usd', 20, 10);
            $table->decimal('spent_usd', 20, 10)->default(0);
            $table->decimal('reserved_usd', 20, 10)->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            // Also the row the budget transactions lock.
            $table->unique(['user_id', 'period_start']);
            $table->index('period_start');
        });

        // No spent <= limit check: actual provider usage is always recorded
        // in full (overshoots are flagged, not rejected).
        DB::statement('ALTER TABLE budget_periods ADD CONSTRAINT budget_periods_amounts_check CHECK (
            limit_usd >= 0 AND spent_usd >= 0 AND reserved_usd >= 0 AND period_end > period_start
        )');

        Schema::create('budget_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('budget_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('ai_model_id')->constrained()->restrictOnDelete();
            $table->decimal('amount_usd', 20, 10);
            $table->decimal('settled_amount_usd', 20, 10)->nullable();
            $table->unsignedInteger('input_tokens');
            $table->string('input_count_method', 24);
            $table->decimal('input_safety_margin', 5, 4);
            $table->unsignedInteger('max_output_tokens');
            $table->string('status', 16);
            $table->string('status_reason', 64)->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('settled_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['status', 'expires_at']);
            $table->index(['user_id', 'status']);
        });

        DB::statement("ALTER TABLE budget_reservations ADD CONSTRAINT budget_reservations_check CHECK (
            amount_usd >= 0
            AND status IN ('active', 'settled', 'released', 'expired')
            AND input_count_method IN ('provider_endpoint', 'local_tokenizer', 'estimated')
        )");

        Schema::create('usage_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 16);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('group_id')->constrained()->restrictOnDelete();
            $table->foreignId('budget_period_id')->constrained()->restrictOnDelete();
            $table->uuid('reservation_id')->nullable()->unique();
            // Loose references: conversation retention never touches the ledger.
            $table->uuid('conversation_id')->nullable();
            $table->uuid('message_id')->nullable();
            $table->foreignId('provider_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('model_alias_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source', 16)->default('chat');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cached_input_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->decimal('input_price_snapshot', 14, 6)->nullable();
            $table->decimal('cached_input_price_snapshot', 14, 6)->nullable();
            $table->decimal('cache_write_price_snapshot', 14, 6)->nullable();
            $table->decimal('output_price_snapshot', 14, 6)->nullable();
            $table->decimal('input_cost_usd', 20, 10)->default(0);
            $table->decimal('output_cost_usd', 20, 10)->default(0);
            $table->decimal('other_cost_usd', 20, 10)->default(0);
            $table->decimal('total_cost_usd', 20, 10);
            $table->boolean('is_estimated')->default(false);
            $table->string('input_count_method', 24)->nullable();
            $table->unsignedInteger('reserved_input_tokens')->nullable();
            $table->string('provider_request_id')->nullable();
            $table->string('status', 16)->default('completed');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at');

            $table->foreign('reservation_id')->references('id')->on('budget_reservations')->restrictOnDelete();
            $table->index(['user_id', 'created_at']);
            $table->index(['group_id', 'created_at']);
            $table->index(['ai_model_id', 'created_at']);
            $table->index(['provider_id', 'created_at']);
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE usage_events ADD CONSTRAINT usage_events_check CHECK (
            type IN ('charge', 'adjustment')
            AND status IN ('completed', 'partial', 'failed')
            AND (type = 'adjustment' OR (
                total_cost_usd >= 0 AND input_cost_usd >= 0 AND output_cost_usd >= 0 AND other_cost_usd >= 0
            ))
            AND (type = 'charge' OR reason IS NOT NULL)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
        Schema::dropIfExists('budget_reservations');
        Schema::dropIfExists('budget_periods');

        DB::statement('ALTER TABLE users DROP CHECK users_limit_override_check');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('monthly_limit_override_usd');
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->dropForeign(['budget_policy_id']);
            $table->dropColumn(['budget_policy_id', 'requests_per_minute', 'max_concurrent_streams']);
        });

        Schema::dropIfExists('budget_policies');
    }
};
