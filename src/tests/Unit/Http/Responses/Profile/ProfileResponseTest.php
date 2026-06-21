<?php

namespace Tests\Unit\Http\Responses\Profile;

use App\Http\Responses\Profile\ProfileListResponse;
use App\Http\Responses\Profile\ProfileResponse;
use App\Models\Profile;
use App\Models\Voice;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProfileResponseTest extends TestCase
{
    public function test_profile_response_returns_profile_payload(): void
    {
        $profile = new Profile;
        $profile->setRawAttributes([
            'id' => 10,
            'user_id' => 20,
            'alias' => 'Demo Alias',
            'name' => 'Demo Profile',
            'description' => 'Profile used for tests.',
            'genre' => 'neutral',
            'personality' => 'friendly',
            'active' => true,
            'status' => 'draft',
            'data' => json_encode(['me' => ['bio' => 'test']]),
        ], true);
        $profile->created_at = Carbon::parse('2026-05-26 10:00:00', 'UTC');
        $profile->updated_at = Carbon::parse('2026-05-26 11:00:00', 'UTC');

        $payload = (new ProfileResponse($profile))->toArray();

        $this->assertSame(10, $payload['id']);
        $this->assertSame(20, $payload['user_id']);
        $this->assertSame('Demo Alias', $payload['alias']);
        $this->assertSame('Demo Profile', $payload['name']);
        $this->assertSame('Profile used for tests.', $payload['description']);
        $this->assertSame('neutral', $payload['genre']);
        $this->assertSame('friendly', $payload['personality']);
        $this->assertTrue($payload['active']);
        $this->assertSame('draft', $payload['status']);
        $this->assertFalse($payload['voice']);
        $this->assertNull($payload['voice_id']);
        $this->assertSame(['me' => ['bio' => 'test']], $payload['data']);
        $this->assertSame('2026-05-26T10:00:00.000000Z', $payload['created_at']);
        $this->assertSame('2026-05-26T11:00:00.000000Z', $payload['updated_at']);
    }

    public function test_profile_response_returns_true_when_profile_has_configured_voice(): void
    {
        $profile = new Profile;
        $profile->setRawAttributes([
            'id' => 10,
            'user_id' => 20,
            'alias' => null,
            'name' => 'Demo Profile',
            'description' => 'Profile used for tests.',
            'genre' => 'neutral',
            'personality' => 'friendly',
            'active' => true,
            'status' => 'ready',
            'data' => null,
        ], true);

        $voice = new Voice;
        $voice->setRawAttributes([
            'id' => 30,
            'source_voice_id' => 'provider-voice-id',
            'source' => 'elevenlabs',
            'active' => true,
        ], true);

        $profile->setRelation('voices', collect([$voice]));

        $payload = (new ProfileResponse($profile))->toArray();

        $this->assertTrue($payload['voice']);
        $this->assertSame(30, $payload['voice_id']);
    }

    public function test_profile_response_returns_false_when_voice_source_fields_are_empty(): void
    {
        $profile = new Profile;
        $profile->setRawAttributes([
            'id' => 10,
            'user_id' => 20,
            'alias' => null,
            'name' => 'Demo Profile',
            'description' => 'Profile used for tests.',
            'genre' => 'neutral',
            'personality' => 'friendly',
            'active' => true,
            'status' => 'hidden',
            'data' => null,
        ], true);

        $voice = new Voice;
        $voice->setRawAttributes([
            'id' => 31,
            'source_voice_id' => 'provider-voice-id',
            'source' => '',
            'active' => true,
        ], true);

        $profile->setRelation('voices', collect([$voice]));

        $payload = (new ProfileResponse($profile))->toArray();

        $this->assertFalse($payload['voice']);
        $this->assertSame(31, $payload['voice_id']);
    }

    public function test_profile_list_response_returns_profiles_and_total(): void
    {
        $firstProfile = new Profile;
        $firstProfile->setRawAttributes([
            'id' => 10,
            'user_id' => 20,
            'alias' => 'First Alias',
            'name' => 'First Profile',
            'description' => 'First profile description.',
            'genre' => 'neutral',
            'personality' => 'friendly',
            'active' => true,
            'status' => 'published',
            'data' => null,
        ], true);

        $secondProfile = new Profile;
        $secondProfile->setRawAttributes([
            'id' => 11,
            'user_id' => 20,
            'alias' => 'Second Alias',
            'name' => 'Second Profile',
            'description' => 'Second profile description.',
            'genre' => 'neutral',
            'personality' => 'helpful',
            'active' => false,
            'status' => 'draft',
            'data' => null,
        ], true);

        $payload = (new ProfileListResponse(collect([$firstProfile, $secondProfile])))->toArray();

        $this->assertSame(2, $payload['total']);
        $this->assertCount(2, $payload['profiles']);
        $this->assertSame(10, $payload['profiles'][0]['id']);
        $this->assertSame(11, $payload['profiles'][1]['id']);
        $this->assertSame('First Alias', $payload['profiles'][0]['alias']);
        $this->assertSame('Second Alias', $payload['profiles'][1]['alias']);
        $this->assertSame('published', $payload['profiles'][0]['status']);
        $this->assertSame('draft', $payload['profiles'][1]['status']);
        $this->assertFalse($payload['profiles'][1]['active']);
        $this->assertFalse($payload['profiles'][0]['voice']);
    }
}
