<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Azure OpenAI (v1 API) joins the drivers.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE providers DROP CHECK providers_driver_check');
        DB::statement("ALTER TABLE providers ADD CONSTRAINT providers_driver_check CHECK (driver IN ('openai', 'anthropic', 'gemini', 'openai_compatible', 'azure_openai'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE providers DROP CHECK providers_driver_check');
        DB::statement("ALTER TABLE providers ADD CONSTRAINT providers_driver_check CHECK (driver IN ('openai', 'anthropic', 'gemini', 'openai_compatible'))");
    }
};
