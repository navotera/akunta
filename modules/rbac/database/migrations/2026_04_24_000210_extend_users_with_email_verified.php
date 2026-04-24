<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend rbac `users` with email_verified_at (step 14-i). Powers the auto-link
 * rule in Google SSO: if user has verified email and OAuth provider returns
 * matching email, link automatically. Otherwise prompt for confirmation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('email_verified_at')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_verified_at');
        });
    }
};
