<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed documents of an assistant: their text is added to its instructions
 * (docs/assistants.md). Files live on the private disk under
 * assistants/{assistant}/{uuid}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('assistant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('original_name');
            $table->string('mime', 127);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->string('path');
            $table->mediumText('extracted_text');
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedInteger('token_estimate');
            $table->integer('sort_order')->default(0);
            $table->dateTime('created_at')->nullable();

            $table->index(['assistant_id', 'sort_order']);
        });

        DB::statement("ALTER TABLE assistant_documents ADD CONSTRAINT assistant_documents_kind_check CHECK (kind IN ('text', 'pdf', 'document'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_documents');
    }
};
