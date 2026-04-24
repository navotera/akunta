<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->string('token_hash', 64)->unique();
            $table->ulid('user_id')->nullable();
            $table->ulid('app_id')->nullable();
            $table->json('permissions');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('app_id')->references('id')->on('apps')->nullOnDelete();

            $table->index('token_hash');
            $table->index(['user_id', 'revoked_at']);
            $table->index(['app_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
