<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Institutional assistants: instructions on top of a model alias, offered to
 * chosen groups (docs/assistants.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistants', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->collation('utf8mb4_bin')->unique();
            $table->json('name');
            $table->json('description')->nullable();
            $table->text('instructions');
            $table->foreignId('model_alias_id')->constrained()->restrictOnDelete();
            $table->json('starter_prompts')->nullable();
            $table->string('icon', 32)->default('sparkles');
            $table->integer('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('assistant_group', function (Blueprint $table) {
            $table->foreignId('assistant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->dateTime('created_at')->nullable();

            $table->primary(['assistant_id', 'group_id']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('assistant_id')->nullable()->after('model_alias_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assistant_id');
        });
        Schema::dropIfExists('assistant_group');
        Schema::dropIfExists('assistants');
    }
};
