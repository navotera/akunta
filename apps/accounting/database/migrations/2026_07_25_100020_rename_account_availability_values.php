<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounts')->where('availability', 'internal')->update(['availability' => 'intern']);
        DB::table('accounts')->where('availability', 'fiscal')->update(['availability' => 'fiskal']);
    }

    public function down(): void
    {
        DB::table('accounts')->where('availability', 'intern')->update(['availability' => 'internal']);
        DB::table('accounts')->where('availability', 'fiskal')->update(['availability' => 'fiscal']);
    }
};
