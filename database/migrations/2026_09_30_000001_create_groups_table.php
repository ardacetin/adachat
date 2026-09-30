<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Groups are the organisational policy unit. Every user belongs to
     * exactly one group, so a default group always exists. Budget policy,
     * rate limits and model permissions are added to groups in later
     * milestones (M5/M7).
     */
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        $now = now();

        $defaultGroupId = DB::table('groups')->insertGetId([
            'name' => 'Default',
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('role');
        });

        DB::table('users')->whereNull('group_id')->update(['group_id' => $defaultGroupId]);

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable(false)->change();
            $table->foreign('group_id')->references('id')->on('groups')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
            $table->dropColumn('group_id');
        });

        Schema::dropIfExists('groups');
    }
};
