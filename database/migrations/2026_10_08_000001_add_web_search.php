<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web search through the providers' own search tools: which models can
 * search and what a search costs, which aliases may search and how often
 * per message, and the searches each request paid for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->boolean('supports_web_search')->default(false)->after('supports_reasoning');
            // USD per 1,000 searches.
            $table->decimal('web_search_price_per_thousand', 14, 6)->nullable()->after('cache_write_price_per_million');
        });

        Schema::table('model_aliases', function (Blueprint $table) {
            $table->boolean('web_search_enabled')->default(false)->after('show_model_details');
            $table->unsignedTinyInteger('web_search_max_uses')->default(3)->after('web_search_enabled');
        });

        Schema::table('usage_events', function (Blueprint $table) {
            $table->unsignedInteger('web_search_requests')->default(0)->after('reasoning_tokens');
            $table->decimal('web_search_price_snapshot', 14, 6)->nullable()->after('output_price_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->dropColumn(['web_search_requests', 'web_search_price_snapshot']);
        });

        Schema::table('model_aliases', function (Blueprint $table) {
            $table->dropColumn(['web_search_enabled', 'web_search_max_uses']);
        });

        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropColumn(['supports_web_search', 'web_search_price_per_thousand']);
        });
    }
};
