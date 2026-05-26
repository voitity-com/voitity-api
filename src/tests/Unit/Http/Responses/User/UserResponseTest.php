<?php

namespace Tests\Unit\Http\Responses\User;

use App\Http\Responses\User\UserResponse;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserResponseTest extends TestCase
{
    public function test_to_array_returns_safe_user_payload(): void
    {
        $user = new User();
        $user->setRawAttributes([
            'id' => 10,
            'name' => 'Abel Moreno',
            'first_name' => 'Abel',
            'last_name' => 'Moreno',
            'email' => 'moreno.abel@gmail.com',
            'role' => 'admin',
            'avatar' => 'https://example.com/avatar.jpg',
            'provider' => 'email',
            'password' => 'secret-password',
            'remember_token' => 'secret-token',
            'google_id' => 'google-secret-id',
        ], true);

        $user->email_verified_at = Carbon::parse('2026-05-26 10:00:00', 'UTC');
        $user->google_verified_at = Carbon::parse('2026-05-26 11:00:00', 'UTC');
        $user->created_at = Carbon::parse('2026-05-26 12:00:00', 'UTC');
        $user->updated_at = Carbon::parse('2026-05-26 13:00:00', 'UTC');

        $payload = (new UserResponse($user))->toArray();

        $this->assertSame(10, $payload['id']);
        $this->assertSame('Abel Moreno', $payload['name']);
        $this->assertSame('Abel', $payload['first_name']);
        $this->assertSame('Moreno', $payload['last_name']);
        $this->assertSame('moreno.abel@gmail.com', $payload['email']);
        $this->assertSame('admin', $payload['role']);
        $this->assertSame('https://example.com/avatar.jpg', $payload['avatar']);
        $this->assertSame('email', $payload['provider']);
        $this->assertSame('2026-05-26T10:00:00.000000Z', $payload['email_verified_at']);
        $this->assertSame('2026-05-26T11:00:00.000000Z', $payload['google_verified_at']);
        $this->assertSame('2026-05-26T12:00:00.000000Z', $payload['created_at']);
        $this->assertSame('2026-05-26T13:00:00.000000Z', $payload['updated_at']);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('remember_token', $payload);
        $this->assertArrayNotHasKey('google_id', $payload);
    }
}
