<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fake_data_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->string('group_key', 64);
            $table->string('model_type', 160);
            $table->ulid('model_id');
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('entities')->cascadeOnDelete();
            $table->unique(['entity_id', 'group_key', 'model_type', 'model_id']);
            $table->index(['entity_id', 'group_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fake_data_records');
    }
};
