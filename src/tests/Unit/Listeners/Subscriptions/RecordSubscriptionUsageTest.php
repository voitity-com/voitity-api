<?php

namespace Tests\Unit\Listeners\Subscriptions;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Enums\SubscriptionUsageType;
use App\Events\Subscriptions\SubscriptionUsageRequested;
use App\Listeners\Subscriptions\RecordSubscriptionUsage;
use App\Models\Profile;
use App\Models\SubscriptionLimit;
use App\Models\User;
use Tests\TestCase;

class RecordSubscriptionUsageTest extends TestCase
{
    public function test_it_records_subscription_usage_from_event(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Description',
            'genre' => 'neutral',
            'personality' => 'friendly',
            'active' => true,
        ]);

        $event = new SubscriptionUsageRequested(
            userId: $user->id,
            usageType: SubscriptionUsageType::VoiceTtsCharacters,
            amounts: ['tts_characters' => 150],
            profileId: $profile->id,
            sourceType: Profile::class,
            sourceId: (string) $profile->id,
            idempotencyKey: 'tts:test'
        );

        (new RecordSubscriptionUsage(new SubscriptionUsageRecorder))->handle($event);

        $limit = SubscriptionLimit::first();

        $this->assertSame(9850, $limit->tts_characters_remaining);
        $this->assertSame(992.5, $limit->credits_remaining);
        $this->assertDatabaseHas('subscription_uses', [
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'usage_type' => SubscriptionUsageType::VoiceTtsCharacters->value,
            'tts_characters_used' => 150,
            'credits_used' => 7.5,
            'idempotency_key' => 'tts:test',
        ]);
    }
}
