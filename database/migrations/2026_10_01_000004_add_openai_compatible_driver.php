<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Allow the generic OpenAI-compatible (Chat Completions) driver.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE providers DROP CHECK providers_driver_check');
        DB::statement("ALTER TABLE providers ADD CONSTRAINT providers_driver_check CHECK (driver IN ('openai', 'anthropic', 'gemini', 'openai_compatible'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE providers DROP CHECK providers_driver_check');
        DB::statement("ALTER TABLE providers ADD CONSTRAINT providers_driver_check CHECK (driver IN ('openai', 'anthropic', 'gemini'))");
    }
};
