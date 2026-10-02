<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only links to a conversation (docs/sharing.md). A share is a frozen
 * copy of the conversation at the time it was shared; later messages are
 * never shown. Only a hash of the link's token is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('title')->nullable();
            $table->json('snapshot');
            $table->unsignedInteger('view_count')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('revoked_at')->nullable();

            $table->index(['conversation_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_shares');
    }
};
