<?php

use App\Enums\AvatarGenerationStatus;
use App\Enums\AvatarVariant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_avatars', function (Blueprint $table): void {
            $table->string('original_file', 2048)->nullable()->after('video_duration_seconds');
            $table->string('generation_status', 50)
                ->default(AvatarGenerationStatus::Processing->value)
                ->after('status');
            $table->string('selected_variant', 50)->nullable()->after('generation_status');
        });

        DB::table('profile_avatars')
            ->where('status', 'processing')
            ->update(['generation_status' => AvatarGenerationStatus::Processing->value]);

        DB::table('profile_avatars')
            ->whereIn('status', ['active', 'inactive'])
            ->update(['generation_status' => AvatarGenerationStatus::Completed->value]);

        DB::table('profile_avatars')
            ->whereIn('status', ['active', 'inactive'])
            ->whereNotNull('ai_video_id')
            ->update(['selected_variant' => AvatarVariant::Animation->value]);

        DB::table('profile_avatars')
            ->whereIn('status', ['active', 'inactive'])
            ->whereNull('ai_video_id')
            ->whereNotNull('aiimage_id')
            ->update(['selected_variant' => AvatarVariant::Enhanced->value]);

        DB::table('profile_avatars')
            ->where('status', 'failed')
            ->update(['generation_status' => AvatarGenerationStatus::ImageFailed->value]);

        DB::table('profile_avatars')
            ->where('status', 'failed')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('aiimages')
                    ->whereColumn('aiimages.id', 'profile_avatars.aiimage_id')
                    ->where('aiimages.status', 'succeeded')
                    ->whereNotNull('aiimages.file');
            })
            ->update(['generation_status' => AvatarGenerationStatus::VideoFailed->value]);
    }

    public function down(): void
    {
        Schema::table('profile_avatars', function (Blueprint $table): void {
            $table->dropColumn(['original_file', 'generation_status', 'selected_variant']);
        });
    }
};
