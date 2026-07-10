<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Models\AppNotification;
use App\Models\User;
use Tests\TestCase;

class AppNotificationControllerTest extends TestCase
{
    private const ENDPOINT = '/api/notifications';

    public function test_it_lists_localized_notifications_and_unread_count(): void
    {
        $user = User::factory()->create(['locale' => 'es']);
        AppNotification::create([
            'user_id' => $user->id,
            'notification_key' => 'profile_created',
            'category' => 'profile',
            'data' => ['profile' => 'Abel Dev'],
        ]);

        $token = $user->createToken('test-token', ['user:read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT.'?locale=es')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.notifications.0.title', 'Perfil creado')
            ->assertJsonPath('data.notifications.0.body', 'El perfil Abel Dev fue creado.');
    }

    public function test_it_marks_and_dismisses_notifications_for_owner_only(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $notification = AppNotification::create([
            'user_id' => $user->id,
            'notification_key' => 'profile_updated',
            'category' => 'profile',
            'data' => ['profile' => 'Abel Dev'],
        ]);
        $otherNotification = AppNotification::create([
            'user_id' => $otherUser->id,
            'notification_key' => 'profile_updated',
            'category' => 'profile',
            'data' => ['profile' => 'Other'],
        ]);
        $token = $user->createToken('test-token', ['user:write'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson(self::ENDPOINT.'/'.$notification->id.'/read')
            ->assertOk();

        $this->assertNotNull($notification->fresh()->read_at);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson(self::ENDPOINT.'/'.$notification->id)
            ->assertOk();

        $this->assertNotNull($notification->fresh()->dismissed_at);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson(self::ENDPOINT.'/'.$otherNotification->id.'/read')
            ->assertNotFound();
    }

    public function test_it_marks_all_notifications_as_read(): void
    {
        $user = User::factory()->create();
        AppNotification::create([
            'user_id' => $user->id,
            'notification_key' => 'profile_created',
            'category' => 'profile',
            'data' => ['profile' => 'One'],
        ]);
        AppNotification::create([
            'user_id' => $user->id,
            'notification_key' => 'profile_updated',
            'category' => 'profile',
            'data' => ['profile' => 'Two'],
        ]);
        $token = $user->createToken('test-token', ['user:write'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson(self::ENDPOINT.'/read-all')
            ->assertOk();

        $this->assertSame(0, $user->appNotifications()->whereNull('read_at')->count());
    }
}
