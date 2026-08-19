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
            $table->string('theme_color', 32)->default('blue')->after('workspace_settings');
            $table->string('logo_path')->nullable()->after('theme_color');
            $table->string('director_name')->nullable()->after('logo_path');
            $table->string('phone', 64)->nullable()->after('director_name');
            $table->string('email')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table): void {
            $table->dropColumn(['theme_color', 'logo_path', 'director_name', 'phone', 'email']);
        });
    }
};
