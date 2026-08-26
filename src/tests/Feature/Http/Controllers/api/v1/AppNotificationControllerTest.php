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

    public function test_it_groups_new_chat_notifications_by_profile_and_day_before_paginating(): void
    {
        $user = User::factory()->create(['locale' => 'es']);
        $firstChat = AppNotification::create([
            'user_id' => $user->id,
            'notification_key' => 'new_chat_received',
            'category' => 'chat',
            'data' => [
                'profile' => 'Perfil principal',
                'profile_id' => 15,
                'chat_id' => 101,
                'action_url' => '/dashboard/profiles/15/chats/101',
            ],
        ]);
        $secondChat = AppNotification::create([
            'user_id' => $user->id,
            'notification_key' => 'new_chat_received',
            'category' => 'chat',
            'data' => [
                'profile' => 'Perfil principal',
                'profile_id' => 15,
                'chat_id' => 102,
                'action_url' => '/dashboard/profiles/15/chats/102',
            ],
            'read_at' => now(),
        ]);
        AppNotification::create([
            'user_id' => $user->id,
            'notification_key' => 'new_chat_received',
            'category' => 'chat',
            'data' => [
                'profile' => 'Perfil secundario',
                'profile_id' => 20,
                'chat_id' => 201,
                'action_url' => '/dashboard/profiles/20/chats/201',
            ],
        ]);
        $yesterdayChat = AppNotification::create([
            'user_id' => $user->id,
            'notification_key' => 'new_chat_received',
            'category' => 'chat',
            'data' => [
                'profile' => 'Perfil principal',
                'profile_id' => 15,
                'chat_id' => 99,
                'action_url' => '/dashboard/profiles/15/chats/99',
            ],
        ]);
        $yesterdayChat->timestamps = false;
        $yesterdayChat->forceFill([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->save();
        $token = $user->createToken('test-token', ['user:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT.'?kind=notification&locale=es&per_page=2&group_chats=1');

        $response
            ->assertOk()
            ->assertJsonPath('data.unread_count', 3)
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonCount(2, 'data.notifications');

        $groups = collect($response->json('data.notifications'));
        $profileGroup = $groups->firstWhere('profile_id', 15);

        $this->assertIsArray($profileGroup);
        $this->assertSame('group', $profileGroup['type']);
        $this->assertSame('new_chat_received', $profileGroup['key']);
        $this->assertSame('Perfil principal', $profileGroup['profile_name']);
        $this->assertSame(2, $profileGroup['count']);
        $this->assertSame(1, $profileGroup['unread_count']);
        $this->assertEqualsCanonicalizing(
            [$firstChat->id, $secondChat->id],
            $profileGroup['notification_ids'],
        );
        $this->assertCount(2, $profileGroup['notifications']);
        $this->assertStringEndsWith('/dashboard/profiles/15/chats', $profileGroup['action_url']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT.'?kind=notification&locale=es')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 4)
            ->assertJsonCount(4, 'data.notifications')
            ->assertJsonPath('data.notifications.0.type', 'notification');
    }

    public function test_grouped_new_chats_respect_the_read_filter(): void
    {
        $user = User::factory()->create();
        AppNotification::create([
            'user_id' => $user->id,
            'notification_key' => 'new_chat_received',
            'category' => 'chat',
            'data' => ['profile' => 'Profile', 'profile_id' => 15, 'chat_id' => 101],
        ]);
        AppNotification::create([
            'user_id' => $user->id,
            'notification_key' => 'new_chat_received',
            'category' => 'chat',
            'data' => ['profile' => 'Profile', 'profile_id' => 15, 'chat_id' => 102],
            'read_at' => now(),
        ]);
        $token = $user->createToken('test-token', ['user:read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT.'?kind=notification&read=unread&group_chats=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('data.notifications.0.type', 'group')
            ->assertJsonPath('data.notifications.0.count', 1)
            ->assertJsonPath('data.notifications.0.unread_count', 1)
            ->assertJsonCount(1, 'data.notifications.0.notifications');
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

    public function test_it_marks_only_selected_owned_notifications_as_read(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $selectedNotification = AppNotification::create([
            'user_id' => $user->id,
            'notification_key' => 'profile_created',
            'category' => 'profile',
            'data' => ['profile' => 'Selected'],
        ]);
        $unselectedNotification = AppNotification::create([
            'user_id' => $user->id,
            'notification_key' => 'profile_updated',
            'category' => 'profile',
            'data' => ['profile' => 'Unselected'],
        ]);
        $otherNotification = AppNotification::create([
            'user_id' => $otherUser->id,
            'notification_key' => 'profile_updated',
            'category' => 'profile',
            'data' => ['profile' => 'Other'],
        ]);
        $token = $user->createToken('test-token', ['user:write'])->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];
        $payload = ['notification_ids' => [$selectedNotification->id, $otherNotification->id]];

        $this->withHeaders($headers)
            ->patchJson(self::ENDPOINT.'/read', $payload)
            ->assertOk()
            ->assertJsonPath('data.marked_read_count', 1);

        $this->assertNotNull($selectedNotification->fresh()->read_at);
        $this->assertNull($unselectedNotification->fresh()->read_at);
        $this->assertNull($otherNotification->fresh()->read_at);

        $this->withHeaders($headers)
            ->patchJson(self::ENDPOINT.'/read', $payload)
            ->assertOk()
            ->assertJsonPath('data.marked_read_count', 0);
    }

    public function test_marking_selected_notifications_requires_write_ability(): void
    {
        $user = User::factory()->create();
        $readToken = $user->createToken('read-token', ['user:read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$readToken)
            ->patchJson(self::ENDPOINT.'/read', ['notification_ids' => [1]])
            ->assertForbidden();
    }

    public function test_marking_selected_notifications_validates_ids(): void
    {
        $user = User::factory()->create();
        $writeToken = $user->createToken('write-token', ['user:write'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$writeToken)
            ->patchJson(self::ENDPOINT.'/read', ['notification_ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('notification_ids');

        $this->withHeader('Authorization', 'Bearer '.$writeToken)
            ->patchJson(self::ENDPOINT.'/read', ['notification_ids' => [1, 1]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('notification_ids.1');
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
