<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_avatars', function (Blueprint $table): void {
            $table->unsignedSmallInteger('video_duration_seconds')->nullable()->after('ai_video_id');
        });

        Schema::table('aivideos', function (Blueprint $table): void {
            $table->unsignedSmallInteger('video_duration_seconds')->nullable()->after('aiimage_id');
        });

        DB::table('profile_avatars')
            ->whereNull('video_duration_seconds')
            ->update(['video_duration_seconds' => 2]);
        DB::table('aivideos')
            ->whereNull('video_duration_seconds')
            ->update(['video_duration_seconds' => 2]);

        $this->alignCurrentStarterLimits();
    }

    public function down(): void
    {
        Schema::table('aivideos', function (Blueprint $table): void {
            $table->dropColumn('video_duration_seconds');
        });

        Schema::table('profile_avatars', function (Blueprint $table): void {
            $table->dropColumn('video_duration_seconds');
        });
    }

    private function alignCurrentStarterLimits(): void
    {
        $rows = DB::table('subscription_limits as limits')
            ->join('subscriptions', 'subscriptions.id', '=', 'limits.subscription_id')
            ->leftJoin('subscription_usage_periods as periods', 'periods.id', '=', 'limits.usage_period_id')
            ->whereIn('subscriptions.plan', ['starter', 'starter_annual'])
            ->select([
                'limits.id as limit_id',
                'limits.avatar_video_seconds_remaining',
                'limits.tts_characters_remaining',
                'periods.id as period_id',
                'periods.limits_snapshot',
                'subscriptions.status',
            ])
            ->get();

        foreach ($rows as $row) {
            $snapshot = is_array($row->limits_snapshot)
                ? $row->limits_snapshot
                : json_decode((string) $row->limits_snapshot, true);
            $snapshot = is_array($snapshot) ? $snapshot : [];
            $trialing = $row->status === 'trialing';
            $targetVideoSeconds = 2;
            $targetTtsCharacters = $trialing ? 2000 : 20000;
            $oldVideoSeconds = max(
                (int) $row->avatar_video_seconds_remaining,
                (int) ($snapshot['avatar_video_seconds'] ?? 5)
            );
            $oldTtsCharacters = max(
                (int) $row->tts_characters_remaining,
                (int) ($snapshot['tts_characters'] ?? ($trialing ? 2000 : 10000))
            );
            $videoUsed = max(0, $oldVideoSeconds - (int) $row->avatar_video_seconds_remaining);
            $ttsUsed = max(0, $oldTtsCharacters - (int) $row->tts_characters_remaining);

            DB::table('subscription_limits')->where('id', $row->limit_id)->update([
                'avatar_video_seconds_remaining' => max(0, $targetVideoSeconds - $videoUsed),
                'tts_characters_remaining' => max(0, $targetTtsCharacters - $ttsUsed),
            ]);

            if ($row->period_id) {
                $snapshot['avatar_video_seconds'] = $targetVideoSeconds;
                $snapshot['tts_characters'] = $targetTtsCharacters;
                DB::table('subscription_usage_periods')->where('id', $row->period_id)->update([
                    'limits_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                ]);
            }
        }
    }
};
