<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI providers, their encrypted credentials, the model registry and the
     * user-facing model aliases. Prices are USD per million tokens.
     */
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->collation('utf8mb4_bin')->unique();
            $table->string('driver', 32);
            $table->string('name');
            $table->string('base_url', 2048)->nullable();
            $table->json('options')->nullable();
            $table->boolean('enabled')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        DB::statement("ALTER TABLE providers ADD CONSTRAINT providers_driver_check CHECK (driver IN ('openai', 'anthropic', 'gemini'))");

        Schema::create('provider_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            // Laravel encrypted cast (APP_KEY); never the plain key.
            $table->text('secret');
            $table->char('last_four', 4);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('rotated_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['provider_id', 'is_active']);
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->string('provider_model_id')->collation('utf8mb4_bin');
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->decimal('input_price_per_million', 14, 6);
            $table->decimal('output_price_per_million', 14, 6);
            $table->decimal('cached_input_price_per_million', 14, 6)->nullable();
            $table->decimal('cache_write_price_per_million', 14, 6)->nullable();
            $table->unsignedInteger('context_window');
            $table->unsignedInteger('max_output_tokens');
            $table->boolean('supports_vision')->default(false);
            $table->boolean('supports_files')->default(false);
            $table->boolean('supports_tools')->default(false);
            $table->boolean('supports_reasoning')->default(false);
            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['provider_id', 'provider_model_id']);
            $table->index('enabled');
        });

        DB::statement('ALTER TABLE ai_models ADD CONSTRAINT ai_models_prices_check CHECK (
            input_price_per_million >= 0 AND output_price_per_million >= 0
            AND (cached_input_price_per_million IS NULL OR cached_input_price_per_million >= 0)
            AND (cache_write_price_per_million IS NULL OR cache_write_price_per_million >= 0)
            AND context_window > 0 AND max_output_tokens > 0
        )');

        Schema::create('model_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->collation('utf8mb4_bin')->unique();
            $table->json('name');
            $table->json('description')->nullable();
            $table->foreignId('ai_model_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('max_output_tokens')->nullable();
            $table->decimal('temperature', 3, 2)->nullable();
            $table->text('system_prompt')->nullable();
            $table->boolean('show_model_details')->default(false);
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['enabled', 'sort_order']);
        });

        // Which aliases a group may use (managed from M7 on).
        Schema::create('group_model_alias', function (Blueprint $table) {
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('model_alias_id')->constrained()->cascadeOnDelete();
            $table->dateTime('created_at')->nullable();

            $table->primary(['group_id', 'model_alias_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_model_alias');
        Schema::dropIfExists('model_aliases');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('provider_credentials');
        Schema::dropIfExists('providers');
    }
};
