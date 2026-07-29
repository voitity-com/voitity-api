<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('name', 180);
            $table->boolean('enabled')->default(false)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('profile_feature_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 120)->index();
            $table->boolean('enabled')->default(false)->index();
            $table->timestamps();

            $table->unique(['profile_id', 'feature_key']);
        });

        DB::table('feature_flags')->insert([
            [
                'key' => 'products',
                'name' => 'Products',
                'enabled' => true,
                'metadata' => json_encode(['group' => 'products']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'integrations.instagram',
                'name' => 'Instagram',
                'enabled' => true,
                'metadata' => json_encode(['group' => 'integrations', 'provider' => 'instagram']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'integrations.tiktok',
                'name' => 'TikTok',
                'enabled' => true,
                'metadata' => json_encode(['group' => 'integrations', 'provider' => 'tiktok']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'integrations.onlyfans',
                'name' => 'OnlyFans',
                'enabled' => true,
                'metadata' => json_encode(['group' => 'integrations', 'provider' => 'onlyfans']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_feature_settings');
        Schema::dropIfExists('feature_flags');
    }
};
