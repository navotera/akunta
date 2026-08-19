<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_mapping_raw_data', function (Blueprint $table): void {
            $table->json('source_payload')->nullable()->after('payload');
        });
    }

    public function down(): void
    {
        Schema::table('auto_mapping_raw_data', function (Blueprint $table): void {
            $table->dropColumn('source_payload');
        });
    }
};
