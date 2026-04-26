<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Optional scoping — null = global (any entity)
            $table->ulid('entity_id')->nullable();
            // Subscriber app (Sales, Inventory, etc.) — not enforced FK to allow external subscribers
            $table->string('app_code', 40)->nullable();
            // Event name — wildcards allowed (e.g. "journal.*", "*")
            $table->string('event', 80);
            $table->string('url', 500);
            // HMAC SHA256 secret (raw, not hashed — needed to sign payloads)
            $table->string('secret', 191);
            $table->boolean('is_active')->default(true);
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['event', 'is_active']);
            $table->index(['entity_id', 'event']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('subscription_id');
            $table->string('event', 80);
            $table->json('payload');
            // pending | success | failed | giving_up
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_tried_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->foreign('subscription_id')->references('id')->on('webhook_subscriptions')->cascadeOnDelete();
            $table->index(['status', 'created_at']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
    }
};
