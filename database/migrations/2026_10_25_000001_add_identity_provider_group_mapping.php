<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group mapping: a group lists the identity provider's group values
 * (names or IDs) whose members it takes; the lowest priority wins when a
 * person is in several. A user whose group an administrator set by hand
 * while mapping was on keeps it ("pinned").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->json('idp_groups')->nullable()->after('max_concurrent_streams');
            $table->unsignedSmallInteger('idp_priority')->default(100)->after('idp_groups');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('group_pinned')->default(false)->after('group_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('group_pinned');
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn(['idp_groups', 'idp_priority']);
        });
    }
};
