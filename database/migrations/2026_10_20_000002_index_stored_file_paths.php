<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The nightly orphan-file sweep looks files up by path, 500 at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->index('path');
        });

        Schema::table('assistant_documents', function (Blueprint $table) {
            $table->index('path');
        });
    }

    public function down(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropIndex(['path']);
        });

        Schema::table('assistant_documents', function (Blueprint $table) {
            $table->dropIndex(['path']);
        });
    }
};
