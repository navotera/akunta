<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * social_accounts — pivot between rbac users and external OAuth providers
 * (Google v1; GitHub / Microsoft / etc extensible later).
 *
 * Step 14-i: schema foundation. Per-app Socialite integration + OAuth callback
 * routes ship later as step 14-ii.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('provider_user_id');
            $table->string('email')->nullable();
            $table->string('avatar_url', 500)->nullable();
            $table->timestampTz('linked_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
