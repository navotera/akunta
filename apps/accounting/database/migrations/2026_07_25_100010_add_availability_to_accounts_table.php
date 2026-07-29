<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('availability', 8)->default('intern')->after('is_fiskal');
        });

        DB::table('accounts')->where('is_fiskal', true)->update(['availability' => 'both']);
        DB::table('accounts')->where('is_fiskal', false)->update(['availability' => 'intern']);
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('availability');
        });
    }
};
