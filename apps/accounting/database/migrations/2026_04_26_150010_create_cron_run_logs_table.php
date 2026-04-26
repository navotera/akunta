<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cron_run_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('command');
            $table->string('mutex_name')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->smallInteger('exit_code')->nullable();
            $table->boolean('failed')->default(false);
            $table->text('output')->nullable();
            $table->text('exception')->nullable();
            $table->timestampsTz();

            $table->index('command');
            $table->index('started_at');
            $table->index(['failed', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_run_logs');
    }
};
