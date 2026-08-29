<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecopa_config_integration', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('value')->nullable();
        });

        Schema::create('ecopa_webhook_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id', 120)->unique();
            $table->string('event', 80);
            $table->timestampTz('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecopa_webhook_receipts');
        Schema::dropIfExists('ecopa_config_integration');
    }
};
