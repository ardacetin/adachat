<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Time columns are DATETIME (UTC), declared explicitly instead of
     * timestamps(), which would create TIMESTAMP columns.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('avatar_url', 2048)->nullable();
            $table->string('role', 32)->default('user');
            $table->string('locale', 8)->nullable();
            $table->string('appearance', 8)->default('system');
            $table->string('status', 16)->default('active');
            $table->dateTime('disabled_at')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->dateTime('last_active_at')->nullable();
            $table->rememberToken();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['status', 'last_active_at']);
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin', 'admin', 'user'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'disabled'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_appearance_check CHECK (appearance IN ('light', 'dark', 'system'))");

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
