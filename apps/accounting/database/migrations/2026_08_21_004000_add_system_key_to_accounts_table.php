<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('system_key', 80)->nullable()->after('legal_basis');
            $table->unique(['entity_id', 'system_key']);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropUnique(['entity_id', 'system_key']);
            $table->dropColumn('system_key');
        });
    }
};
