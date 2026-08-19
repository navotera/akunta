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
            $table->string('workspace_code', 64)->nullable()->after('name');
            $table->boolean('is_active')->default(true)->after('workspace_code');
            $table->json('workspace_settings')->nullable()->after('is_active');
            $table->index(['tenant_id', 'is_active']);
            $table->unique(['tenant_id', 'workspace_code']);
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'workspace_code']);
            $table->dropIndex(['tenant_id', 'is_active']);
            $table->dropColumn(['workspace_code', 'is_active', 'workspace_settings']);
        });
    }
};
