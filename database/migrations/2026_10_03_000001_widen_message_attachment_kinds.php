<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PDF and Office documents (v1.1 N5) and their page/slide count.
     */
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->unsignedSmallInteger('page_count')->nullable()->after('height');
        });

        DB::statement('ALTER TABLE message_attachments DROP CHECK message_attachments_kind_check');
        DB::statement("ALTER TABLE message_attachments ADD CONSTRAINT message_attachments_kind_check CHECK (kind IN ('image', 'text', 'pdf', 'document'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE message_attachments DROP CHECK message_attachments_kind_check');
        DB::statement("ALTER TABLE message_attachments ADD CONSTRAINT message_attachments_kind_check CHECK (kind IN ('image', 'text'))");

        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropColumn('page_count');
        });
    }
};
