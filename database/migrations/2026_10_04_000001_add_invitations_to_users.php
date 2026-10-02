<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts an administrator adds by e-mail address before the person signs
 * in. Such an address may sign in even when its domain is not in the
 * allowed domains (docs/authentication.md, "Adding users").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dateTime('invited_at')->nullable()->after('status');
            $table->foreignId('invited_by')->nullable()->after('invited_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn('invited_at');
        });
    }
};
