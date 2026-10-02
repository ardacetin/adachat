<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Thumbs up / down on answers. No user and no text: administrators see only
 * how satisfied users are with each alias, model and assistant. When the
 * conversation is deleted the vote stays, without its message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('message_id')->nullable()->unique()->constrained()->nullOnDelete();
            // Copied from the message, so the totals survive its deletion.
            $table->foreignId('model_alias_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assistant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rating', 4);
            $table->string('reason', 16)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('created_at');
        });

        DB::statement("ALTER TABLE message_feedback ADD CONSTRAINT message_feedback_check CHECK (
            rating IN ('up', 'down')
            AND (reason IS NULL OR (rating = 'down' AND reason IN ('inaccurate', 'unhelpful', 'incomplete', 'too_long', 'other')))
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('message_feedback');
    }
};
