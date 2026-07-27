<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_integration_media', function (Blueprint $table): void {
            $table->string('storage_disk', 80)->nullable()->after('media_url');
            $table->string('storage_path', 2048)->nullable()->after('storage_disk');
            $table->boolean('age_restricted')->default(false)->after('observation')->index();
        });
    }

    public function down(): void
    {
        Schema::table('profile_integration_media', function (Blueprint $table): void {
            $table->dropIndex(['age_restricted']);
            $table->dropColumn(['storage_disk', 'storage_path', 'age_restricted']);
        });
    }
};
