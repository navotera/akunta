<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table): void {
            $table->boolean('is_fake_data')->default(false)->after('is_active');
            $table->index(['tenant_id', 'is_fake_data']);
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'is_fake_data']);
            $table->dropColumn('is_fake_data');
        });
    }
};
