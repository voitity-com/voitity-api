<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Models\User;
use App\Models\UserNotificationPreference;
use PHPUnit\Framework\Attributes\Test;

class NotificationPreferenceControllerTest extends TestAPI
{
    private const ENDPOINT = '/api/notification-preferences';

    #[Test]
    public function unauthenticated_users_cannot_read_notification_preferences(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    #[Test]
    public function users_without_read_ability_cannot_read_notification_preferences(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['profile:read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT)
            ->assertStatus(403);
    }

    #[Test]
    public function users_can_read_default_notification_preferences_from_config(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['user:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)->getJson(self::ENDPOINT);

        $response->assertStatus(200);
        $response->assertJsonPath('data.preferences.0.key', 'product_updates');
        $response->assertJsonPath('data.preferences.0.channel', 'email');
        $response->assertJsonPath('data.preferences.0.enabled', true);
        $response->assertJsonPath('data.preferences.0.default_enabled', true);
        $response->assertJsonPath('data.preferences.1.key', 'security_updates');
        $response->assertJsonPath('data.preferences.1.enabled', false);
        $response->assertJsonCount(2, 'data.preferences');
    }

    #[Test]
    public function users_can_update_their_own_notification_preferences(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('test-token', ['user:write'])->plainTextToken;

        UserNotificationPreference::create([
            'user_id' => $otherUser->id,
            'notification_key' => 'product_updates',
            'channel' => 'email',
            'enabled' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)->patchJson(self::ENDPOINT, [
            'preferences' => [
                'product_updates' => false,
                'security_updates' => true,
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.preferences.0.key', 'product_updates');
        $response->assertJsonPath('data.preferences.0.enabled', false);
        $response->assertJsonPath('data.preferences.1.key', 'security_updates');
        $response->assertJsonPath('data.preferences.1.enabled', true);

        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id' => $user->id,
            'notification_key' => 'product_updates',
            'channel' => 'email',
            'enabled' => false,
        ]);
        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id' => $user->id,
            'notification_key' => 'security_updates',
            'channel' => 'email',
            'enabled' => true,
        ]);
        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id' => $otherUser->id,
            'notification_key' => 'product_updates',
            'channel' => 'email',
            'enabled' => true,
        ]);
    }

    #[Test]
    public function users_without_write_ability_cannot_update_notification_preferences(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['user:read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson(self::ENDPOINT, [
                'preferences' => ['product_updates' => false],
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function update_rejects_unknown_notification_preferences(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['user:write'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson(self::ENDPOINT, [
                'preferences' => ['unknown_notification' => true],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['preferences']);
    }
}
