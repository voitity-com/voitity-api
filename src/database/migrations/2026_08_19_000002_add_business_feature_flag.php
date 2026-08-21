<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('feature_flags')->updateOrInsert(
            ['key' => 'business'],
            [
                'name' => 'Business',
                'enabled' => false,
                'metadata' => json_encode([
                    'group' => 'business',
                    'profile_configurable' => false,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('feature_flags')->where('key', 'business')->delete();
    }
};
