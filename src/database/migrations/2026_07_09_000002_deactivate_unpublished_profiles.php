<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('profiles')
            ->where('status', '<>', 'published')
            ->update(['active' => false]);
    }

    public function down(): void
    {
        // Existing active states cannot be reconstructed safely.
    }
};
