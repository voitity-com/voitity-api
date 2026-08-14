<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('feature_flags')->updateOrInsert(
            ['key' => 'domains.custom'],
            [
                'name' => 'Custom domain',
                'enabled' => true,
                'metadata' => json_encode([
                    'group' => 'domains',
                    'profile_configurable' => false,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('profile_feature_settings')->where('feature_key', 'domains.custom')->delete();
        DB::table('feature_flags')->where('key', 'domains.custom')->delete();
    }
};
