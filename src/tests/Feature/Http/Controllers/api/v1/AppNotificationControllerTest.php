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

    public function test_bell_scope_only_lists_unread_visible_notifications(): void
    {
        $user = User::factory()->create(['locale' => 'en']);
        AppNotification::create([
            'user_id' => $user->id,
            'notification_key' => 'profile_activation_requirements_missing',
            'category' => 'profile',
            'data' => ['profile' => 'One', 'requirements' => 'avatar'],
            'kind' => 'notification',
            'visible_in_bell' => true,
        ]);
        AppNotification::create([
            'user_id' => $user->id,
            'notification_key' => 'profile_updated',
            'category' => 'profile',
            'data' => ['profile' => 'Two'],
            'kind' => 'log',
            'visible_in_bell' => false,
            'read_at' => now(),
        ]);
        AppNotification::create([
            'user_id' => $user->id,
            'notification_key' => 'profile_deactivated',
            'category' => 'profile',
            'data' => ['profile' => 'Three'],
            'kind' => 'notification',
            'visible_in_bell' => true,
            'read_at' => now(),
        ]);
        $token = $user->createToken('test-token', ['user:read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT.'?scope=bell&locale=en')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('data.notifications.0.key', 'profile_activation_requirements_missing')
            ->assertJsonPath('data.notifications.0.kind', 'notification')
            ->assertJsonPath('data.notifications.0.visible_in_bell', true);
    }

    public function test_it_filters_notification_center_by_kind_and_read_status(): void
    {
        $user = User::factory()->create(['locale' => 'en']);
        AppNotification::create([
            'user_id' => $user->id,
            'notification_key' => 'profile_activation_requirements_missing',
            'category' => 'profile',
            'data' => ['profile' => 'One', 'requirements' => 'avatar'],
            'kind' => 'notification',
            'visible_in_bell' => true,
        ]);
        AppNotification::create([
            'user_id' => $user->id,
            'notification_key' => 'admin_impersonation_started',
            'category' => 'admin',
            'data' => ['user' => 'customer@example.com'],
            'kind' => 'log',
            'visible_in_bell' => false,
            'read_at' => now(),
        ]);
        $token = $user->createToken('test-token', ['user:read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT.'?kind=log&read=read&locale=en')
            ->assertOk()
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('data.notifications.0.key', 'admin_impersonation_started')
            ->assertJsonPath('data.notifications.0.kind', 'log');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT.'?kind=notification&read=unread&locale=en')
            ->assertOk()
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('data.notifications.0.key', 'profile_activation_requirements_missing');
    }
}
