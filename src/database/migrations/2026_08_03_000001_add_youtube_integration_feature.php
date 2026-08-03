<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('feature_flags')->updateOrInsert(
            ['key' => 'integrations.youtube'],
            [
                'name' => 'YouTube',
                'enabled' => true,
                'metadata' => json_encode(['group' => 'integrations', 'provider' => 'youtube']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('profile_feature_settings')->where('feature_key', 'integrations.youtube')->delete();
        DB::table('feature_flags')->where('key', 'integrations.youtube')->delete();
    }
};
