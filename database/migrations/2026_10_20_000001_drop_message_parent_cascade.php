<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * messages.parent_message_id cascaded deletes through the whole thread, and
 * MySQL refuses cascades deeper than 15 levels: deleting a conversation with
 * a longer thread failed. Messages only ever go with their conversation
 * (conversation_id cascade), so the thread link keeps just its index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['parent_message_id']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreign('parent_message_id')->references('id')->on('messages')->cascadeOnDelete();
        });
    }
};
