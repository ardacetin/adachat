<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Files attached to chat messages. The file itself lives on the private
     * disk; a row without message_id is an upload that has not been sent yet.
     */
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('original_name');
            $table->string('mime', 127);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->string('path');
            $table->mediumText('extracted_text')->nullable();
            $table->unsignedInteger('token_estimate');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index(['user_id', 'message_id', 'created_at']);
        });

        DB::statement("ALTER TABLE message_attachments ADD CONSTRAINT message_attachments_kind_check CHECK (kind IN ('image', 'text'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
