<?php

namespace Tests\Unit\Classes\Subscriptions;

use App\Classes\Subscriptions\CreditAmount;
use App\Classes\Subscriptions\SubscriptionUsageAccountingRepairService;
use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Enums\SubscriptionUsageType;
use App\Models\AiImage;
use App\Models\CreditWallet;
use App\Models\Message;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\SubscriptionUse;
use App\Models\User;
use Tests\Support\CreatesSubscriptionScenarios;
use Tests\TestCase;

class SubscriptionUsageAccountingRepairServiceTest extends TestCase
{
    use CreatesSubscriptionScenarios;

    public function test_it_repairs_audio_seconds_and_legacy_split_avatar_funding_idempotently(): void
    {
        $user = User::factory()->create();
        [$subscription, $limit] = $this->createConfiguredSubscription($user);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Repair profile',
            'description' => 'Repair scenario',
            'genre' => 'test',
            'personality' => 'friendly',
            'active' => true,
        ]);
        $wallet = CreditWallet::create([
            'user_id' => $user->id,
            'available_units' => CreditAmount::creditsToUnits(1000),
            'lifetime_purchased_units' => CreditAmount::creditsToUnits(1000),
        ]);
        $recorder = app(SubscriptionUsageRecorder::class);

        $recorder->record(
            $user->id,
            SubscriptionUsageType::AvatarGenerated,
            ['avatar_images' => 1, 'avatar_video_seconds' => 2],
            'repair:included-avatar',
            $profile->id,
        );

        $aiImage = AiImage::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source_id' => 'repair-image',
            'source' => 'runway',
            'status' => 'succeeded',
        ]);
        $avatar = ProfileAvatar::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'aiimage_id' => $aiImage->id,
            'video_duration_seconds' => 2,
            'status' => ProfileAvatar::STATUS_ACTIVE,
        ]);
        $recorder->record(
            $user->id,
            SubscriptionUsageType::AvatarImageCreated,
            ['avatar_images' => 1],
            "avatar-image:profile-avatar:{$avatar->id}",
            $profile->id,
            ProfileAvatar::class,
            (string) $avatar->id,
        );
        $this->assertSame(
            ['avatar_images' => 1],
            SubscriptionUse::where('idempotency_key', "avatar-image:profile-avatar:{$avatar->id}")
                ->firstOrFail()
                ->credit_covered,
        );
        $limit->refresh()->update(['avatar_video_seconds_remaining' => 2]);
        $recorder->record(
            $user->id,
            SubscriptionUsageType::AvatarVideoCreated,
            ['avatar_video_seconds' => 2],
            "avatar-video:profile-avatar:{$avatar->id}",
            $profile->id,
            ProfileAvatar::class,
            (string) $avatar->id,
        );
        $this->assertSame(
            ['avatar_video_seconds' => 2],
            SubscriptionUse::where('idempotency_key', "avatar-video:profile-avatar:{$avatar->id}")
                ->firstOrFail()
                ->plan_covered,
        );

        $chat = $profile->chats()->create();
        $message = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Audio question',
            'type' => 'question',
            'source' => 'api',
            'data' => [
                'request' => [
                    'transcription' => ['duration' => 29.9999997],
                ],
            ],
        ]);
        $recorder->record(
            $user->id,
            SubscriptionUsageType::IncomingAudioMessage,
            [
                'chat_messages' => 1,
                'incoming_audio_messages' => 1,
                'incoming_audio_seconds' => 1,
            ],
            'repair:incoming-audio',
            $profile->id,
            Message::class,
            (string) $message->id,
        );

        $first = app(SubscriptionUsageAccountingRepairService::class)->repair($user->id);
        $second = app(SubscriptionUsageAccountingRepairService::class)->repair($user->id);

        $this->assertSame(1, $first['audio_uses_repaired']);
        $this->assertSame(1, $first['avatar_generations_repaired']);
        $this->assertSame(0, $second['audio_uses_repaired']);
        $this->assertSame(0, $second['avatar_generations_repaired']);
        $this->assertDatabaseHas('subscription_uses', [
            'idempotency_key' => 'repair:incoming-audio',
            'incoming_audio_seconds_used' => 30,
            'status' => SubscriptionUse::STATUS_FINALIZED,
        ]);
        $this->assertDatabaseHas('subscription_uses', [
            'idempotency_key' => "avatar-generation:profile-avatar:{$avatar->id}",
            'avatar_images_used' => 1,
            'avatar_video_seconds_used' => 2,
            'purchased_credit_units' => 72500,
            'status' => SubscriptionUse::STATUS_FINALIZED,
        ]);
        $this->assertSame(927500, $wallet->fresh()->available_units);
        $this->assertSame(72500, $wallet->fresh()->lifetime_consumed_units);
        $this->assertSame(0, $limit->fresh()->avatar_images_remaining);
        $this->assertSame(0, $limit->fresh()->avatar_video_seconds_remaining);
        $this->assertSame(14970, $limit->fresh()->incoming_audio_seconds_remaining);
        $this->assertSame($subscription->limit->usage_period_id, $limit->fresh()->usage_period_id);
    }
}
