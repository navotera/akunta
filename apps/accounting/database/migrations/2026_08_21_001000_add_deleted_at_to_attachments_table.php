<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->softDeletes();
            $table->index(['attachable_type', 'attachable_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropIndex(['attachable_type', 'attachable_id', 'deleted_at']);
            $table->dropSoftDeletes();
        });
    }
};
