<?php

namespace Tests\Unit\Http\Responses\Profile;

use App\Http\Responses\Profile\ProfileListResponse;
use App\Http\Responses\Profile\ProfileResponse;
use App\Models\Profile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProfileResponseTest extends TestCase
{
    public function test_profile_response_returns_profile_payload(): void
    {
        $profile = new Profile();
        $profile->setRawAttributes([
            'id' => 10,
            'user_id' => 20,
            'name' => 'Demo Profile',
            'description' => 'Profile used for tests.',
            'genre' => 'neutral',
            'personality' => 'friendly',
            'active' => true,
            'data' => json_encode(['me' => ['bio' => 'test']]),
        ], true);
        $profile->created_at = Carbon::parse('2026-05-26 10:00:00', 'UTC');
        $profile->updated_at = Carbon::parse('2026-05-26 11:00:00', 'UTC');

        $payload = (new ProfileResponse($profile))->toArray();

        $this->assertSame(10, $payload['id']);
        $this->assertSame(20, $payload['user_id']);
        $this->assertSame('Demo Profile', $payload['name']);
        $this->assertSame('Profile used for tests.', $payload['description']);
        $this->assertSame('neutral', $payload['genre']);
        $this->assertSame('friendly', $payload['personality']);
        $this->assertTrue($payload['active']);
        $this->assertSame(['me' => ['bio' => 'test']], $payload['data']);
        $this->assertSame('2026-05-26T10:00:00.000000Z', $payload['created_at']);
        $this->assertSame('2026-05-26T11:00:00.000000Z', $payload['updated_at']);
    }

    public function test_profile_list_response_returns_profiles_and_total(): void
    {
        $firstProfile = new Profile();
        $firstProfile->setRawAttributes([
            'id' => 10,
            'user_id' => 20,
            'name' => 'First Profile',
            'description' => 'First profile description.',
            'genre' => 'neutral',
            'personality' => 'friendly',
            'active' => true,
            'data' => null,
        ], true);

        $secondProfile = new Profile();
        $secondProfile->setRawAttributes([
            'id' => 11,
            'user_id' => 20,
            'name' => 'Second Profile',
            'description' => 'Second profile description.',
            'genre' => 'neutral',
            'personality' => 'helpful',
            'active' => false,
            'data' => null,
        ], true);

        $payload = (new ProfileListResponse(collect([$firstProfile, $secondProfile])))->toArray();

        $this->assertSame(2, $payload['total']);
        $this->assertCount(2, $payload['profiles']);
        $this->assertSame(10, $payload['profiles'][0]['id']);
        $this->assertSame(11, $payload['profiles'][1]['id']);
        $this->assertFalse($payload['profiles'][1]['active']);
    }
}
