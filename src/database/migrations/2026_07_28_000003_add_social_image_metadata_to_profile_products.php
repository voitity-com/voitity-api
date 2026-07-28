<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_products', function (Blueprint $table): void {
            $table->string('social_storage_path', 2048)->nullable()->after('storage_path');
            $table->string('social_image_mime_type', 100)->nullable()->after('social_storage_path');
            $table->unsignedInteger('social_image_width')->nullable()->after('social_image_mime_type');
            $table->unsignedInteger('social_image_height')->nullable()->after('social_image_width');
        });
    }

    public function down(): void
    {
        Schema::table('profile_products', function (Blueprint $table): void {
            $table->dropColumn([
                'social_storage_path',
                'social_image_mime_type',
                'social_image_width',
                'social_image_height',
            ]);
        });
    }
};
