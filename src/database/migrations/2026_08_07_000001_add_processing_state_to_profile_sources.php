<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_sources', function (Blueprint $table): void {
            $table->string('processing_stage', 40)->nullable()->after('status');
            $table->text('last_error')->nullable()->after('processing_stage');
            $table->unsignedInteger('retry_count')->default(0)->after('last_error');
            $table->timestamp('processing_started_at')->nullable()->after('retry_count');
            $table->timestamp('processing_completed_at')->nullable()->after('processing_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('profile_sources', function (Blueprint $table): void {
            $table->dropColumn([
                'processing_stage',
                'last_error',
                'retry_count',
                'processing_started_at',
                'processing_completed_at',
            ]);
        });
    }
};
