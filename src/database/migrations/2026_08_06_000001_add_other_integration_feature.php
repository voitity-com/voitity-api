<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('feature_flags')->updateOrInsert(
            ['key' => 'integrations.other'],
            [
                'name' => 'Other',
                'enabled' => true,
                'metadata' => json_encode(['group' => 'integrations', 'provider' => 'other']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('profile_feature_settings')->where('feature_key', 'integrations.other')->delete();
        DB::table('feature_flags')->where('key', 'integrations.other')->delete();
    }
};
