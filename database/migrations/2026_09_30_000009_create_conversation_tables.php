<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conversations and their messages. The usage ledger references these
     * loosely (no FK), so deleting chats never touches financial records.
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->foreignId('model_alias_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('last_message_at');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();

            // Sidebar list; MySQL has no partial index, so deleted_at is part of it.
            $table->index(['user_id', 'deleted_at', 'last_message_at']);
            $table->index('deleted_at');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->uuid('parent_message_id')->nullable();
            $table->string('role', 16);
            $table->mediumText('content');
            $table->string('status', 16);
            $table->string('error_code', 64)->nullable();
            $table->string('finish_reason', 32)->nullable();
            $table->foreignId('model_alias_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained()->nullOnDelete();
            // Loose reference: reservations outlive conversation retention.
            $table->uuid('reservation_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('parent_message_id')->references('id')->on('messages')->cascadeOnDelete();
            $table->index(['conversation_id', 'created_at']);
            $table->index(['status', 'updated_at']);
        });

        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_check CHECK (
            role IN ('user', 'assistant')
            AND status IN ('completed', 'streaming', 'failed', 'cancelled')
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
