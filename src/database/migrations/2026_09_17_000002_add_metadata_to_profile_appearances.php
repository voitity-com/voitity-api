<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_appearances', function (Blueprint $table): void {
            $table->json('metadata')->nullable()->after('background_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('profile_appearances', function (Blueprint $table): void {
            $table->dropColumn('metadata');
        });
    }
};
