<?php

namespace Tests\Unit\Classes\PublicProfiles;

use App\Classes\PublicProfiles\PublicChatSession;
use App\Models\Chat;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PublicChatSessionTest extends TestCase
{
    public function test_token_is_scoped_to_chat_and_profile_and_rejects_tampering(): void
    {
        $profile = Profile::factory()->for(User::factory())->create();
        $chat = Chat::create(['profile_id' => $profile->id]);
        $otherProfile = Profile::factory()->for(User::factory())->create();
        $otherChat = Chat::create(['profile_id' => $otherProfile->id]);
        $sessions = app(PublicChatSession::class);

        $token = $sessions->issue($profile, $chat);

        $this->assertTrue($sessions->isValid($token, $profile, $chat->id));
        $this->assertFalse($sessions->isValid($token.'tampered', $profile, $chat->id));
        $this->assertFalse($sessions->isValid($token, $otherProfile, $chat->id));
        $this->assertFalse($sessions->isValid($token, $profile, $otherChat->id));
        $this->assertFalse($sessions->isValid(null, $profile, $chat->id));
    }

    public function test_token_expires_after_configured_lifetime(): void
    {
        Carbon::setTestNow('2026-07-30 10:00:00');
        config(['public-profiles.chat_session_lifetime_minutes' => 5]);
        $profile = Profile::factory()->for(User::factory())->create();
        $chat = Chat::create(['profile_id' => $profile->id]);
        $sessions = app(PublicChatSession::class);
        $token = $sessions->issue($profile, $chat);

        Carbon::setTestNow('2026-07-30 10:06:00');

        $this->assertFalse($sessions->isValid($token, $profile, $chat->id));
        Carbon::setTestNow();
    }
}
