<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $this->syncAvatarAiImagesFromVideos();
        $this->backfillSucceededVideoAvatars();
        $this->normalizeSingleStatusPerProfile('active', 'inactive');
        $this->normalizeSingleStatusPerProfile('processing', 'failed');
        $this->createStatusIndexes();
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS profile_avatars_one_active_per_profile');
            DB::statement('DROP INDEX IF EXISTS profile_avatars_one_processing_per_profile');
        }

        DB::statement('DROP INDEX IF EXISTS profile_avatars_profile_status_updated_idx');
    }

    private function syncAvatarAiImagesFromVideos(): void
    {
        DB::table('profile_avatars')
            ->whereNotNull('ai_video_id')
            ->orderBy('id')
            ->get()
            ->each(function ($avatar): void {
                $video = DB::table('aivideos')
                    ->select('aiimage_id')
                    ->where('id', $avatar->ai_video_id)
                    ->first();

                if ($video?->aiimage_id && $avatar->aiimage_id !== $video->aiimage_id) {
                    DB::table('profile_avatars')
                        ->where('id', $avatar->id)
                        ->update([
                            'aiimage_id' => $video->aiimage_id,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    private function backfillSucceededVideoAvatars(): void
    {
        $latestSucceededVideoByProfile = DB::table('aivideos')
            ->select('profile_id', DB::raw('MAX(id) as id'))
            ->whereNotNull('profile_id')
            ->whereNotNull('file')
            ->where('status', 'succeeded')
            ->groupBy('profile_id')
            ->pluck('id', 'profile_id');

        $activeAvatarByProfile = DB::table('profile_avatars')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->pluck('id', 'profile_id');

        DB::table('aivideos')
            ->whereNotNull('profile_id')
            ->whereNotNull('file')
            ->where('status', 'succeeded')
            ->orderBy('id')
            ->get()
            ->each(function ($video) use ($latestSucceededVideoByProfile, &$activeAvatarByProfile): void {
                $exists = DB::table('profile_avatars')
                    ->where('ai_video_id', $video->id)
                    ->whereNull('deleted_at')
                    ->exists();

                if ($exists) {
                    return;
                }

                $status = 'inactive';

                if (!isset($activeAvatarByProfile[$video->profile_id])
                    && (int) ($latestSucceededVideoByProfile[$video->profile_id] ?? 0) === (int) $video->id
                ) {
                    $status = 'active';
                }

                $id = DB::table('profile_avatars')->insertGetId([
                    'user_id' => $video->user_id,
                    'profile_id' => $video->profile_id,
                    'aiimage_id' => $video->aiimage_id,
                    'ai_video_id' => $video->id,
                    'file' => $video->file,
                    'status' => $status,
                    'created_at' => $video->created_at ?? now(),
                    'updated_at' => $video->updated_at ?? now(),
                ]);

                if ($status === 'active') {
                    $activeAvatarByProfile[$video->profile_id] = $id;
                }
            });
    }

    private function normalizeSingleStatusPerProfile(string $statusToKeep, string $statusForOthers): void
    {
        DB::table('profile_avatars')
            ->where('status', $statusToKeep)
            ->whereNull('deleted_at')
            ->select('profile_id', DB::raw('COUNT(*) as total'))
            ->groupBy('profile_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->each(function ($row) use ($statusToKeep, $statusForOthers): void {
                $keepId = DB::table('profile_avatars')
                    ->where('profile_id', $row->profile_id)
                    ->where('status', $statusToKeep)
                    ->whereNull('deleted_at')
                    ->orderByDesc('updated_at')
                    ->orderByDesc('id')
                    ->value('id');

                DB::table('profile_avatars')
                    ->where('profile_id', $row->profile_id)
                    ->where('status', $statusToKeep)
                    ->whereNull('deleted_at')
                    ->where('id', '<>', $keepId)
                    ->update([
                        'status' => $statusForOthers,
                        'updated_at' => now(),
                    ]);
            });
    }

    private function createStatusIndexes(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS profile_avatars_one_active_per_profile
                ON profile_avatars (profile_id)
                WHERE status = 'active' AND deleted_at IS NULL"
            );

            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS profile_avatars_one_processing_per_profile
                ON profile_avatars (profile_id)
                WHERE status = 'processing' AND deleted_at IS NULL"
            );
        }

        DB::statement(
            'CREATE INDEX IF NOT EXISTS profile_avatars_profile_status_updated_idx
            ON profile_avatars (profile_id, status, updated_at)'
        );
    }
};
