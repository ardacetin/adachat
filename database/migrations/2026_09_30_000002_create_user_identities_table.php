<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links users to external identity providers. The stable subject (sub)
     * identifies a person even if their e-mail address changes.
     */
    public function up(): void
    {
        Schema::create('user_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->collation('utf8mb4_bin');
            $table->string('subject')->collation('utf8mb4_bin');
            $table->string('email');
            // Non-secret claims for troubleshooting (e.g. hd, email_verified); never tokens.
            $table->json('last_claims')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['provider', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identities');
    }
};
