<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Enums\ProfileSourceStatus;
use App\Enums\ProfileSourceType;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\AiImage;
use App\Models\AiVideo;
use App\Models\Chat;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\ProfileSource;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\User;
use App\Models\Voice;
use Laravel\Sanctum\PersonalAccessToken;

class AdminUserControllerTest extends TestAPI
{
    private const ENDPOINT_USERS = '/api/admin/users';

    public function test_admin_users_permissions_are_only_configured_for_admin_role(): void
    {
        $this->assertContains('admin.users.view', config('roles.admin.abilities'));
        $this->assertContains('admin.users.impersonate', config('roles.admin.abilities'));
        $this->assertContains('admin.users.subscriptions.manage', config('roles.admin.abilities'));

        foreach (['user', 'profile', 'api'] as $role) {
            $this->assertNotContains('admin.users.view', config("roles.{$role}.abilities"));
            $this->assertNotContains('admin.users.impersonate', config("roles.{$role}.abilities"));
            $this->assertNotContains('admin.users.subscriptions.manage', config("roles.{$role}.abilities"));
        }
    }

    public function test_admin_without_admin_users_view_ability_can_list_users(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('test-token', ['user:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->json('GET', self::ENDPOINT_USERS);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Users retrieved successfully.');
    }

    public function test_non_admin_user_with_admin_users_view_ability_can_not_list_users(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $token = $user->createToken('test-token', ['admin.users.view'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->json('GET', self::ENDPOINT_USERS);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Forbidden.');
    }

    public function test_admin_can_list_users_with_resource_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'name' => 'Target User',
            'email' => 'target@example.com',
            'role' => 'user',
        ]);
        $profile = $this->createProfileResources($user);
        $token = $admin->createToken('test-token', ['user:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->json('GET', self::ENDPOINT_USERS.'?search=target');

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Users retrieved successfully.');
        $response->assertJsonPath('data.users.0.id', $user->id);
        $response->assertJsonPath('data.users.0.counts.profiles', 1);
        $response->assertJsonPath('data.users.0.counts.sources', 1);
        $response->assertJsonPath('data.users.0.counts.avatars', 1);
        $response->assertJsonPath('data.users.0.counts.voices', 1);
        $response->assertJsonPath('data.users.0.counts.ai_images', 1);
        $response->assertJsonPath('data.users.0.counts.ai_videos', 1);
        $response->assertJsonPath('data.users.0.counts.chats', 1);
        $this->assertContains('admin', collect($response->json('data.subscription_plans'))->pluck('id')->all());
        $this->assertNotContains('pro', collect($response->json('data.subscription_plans'))->pluck('id')->all());
        $this->assertNotContains('business', collect($response->json('data.subscription_plans'))->pluck('id')->all());
        $this->assertSame(0, collect($response->json('data.subscription_plans'))->firstWhere('id', 'admin')['price_usd']);
        $response->assertJsonPath('data.pagination.total', 1);

        $this->assertSame($user->id, $profile->user_id);
    }

    public function test_admin_can_show_user_with_profile_resource_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileResources($user);
        $token = $admin->createToken('test-token', ['user:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->json('GET', self::ENDPOINT_USERS.'/'.$user->id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.profiles.0.id', $profile->id);
        $response->assertJsonPath('data.profiles.0.counts.sources', 1);
        $response->assertJsonPath('data.profiles.0.counts.avatars', 1);
        $response->assertJsonPath('data.profiles.0.counts.voices', 1);
        $response->assertJsonPath('data.profiles.0.counts.chats', 1);
        $response->assertJsonPath('data.profiles.0.counts.ai_images', 1);
        $response->assertJsonPath('data.profiles.0.counts.ai_videos', 1);
    }

    public function test_admin_can_assign_admin_subscription_to_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $previousSubscription = Subscription::create([
            'user_id' => $user->id,
            'plan' => SubscriptionPlan::Starter,
            'started_at' => now()->subDay(),
            'renews_at' => now()->addMonth(),
            'status' => SubscriptionStatus::First,
            'active' => true,
        ]);
        SubscriptionLimit::create([
            'subscription_id' => $previousSubscription->id,
            'user_id' => $user->id,
            'period_started_at' => $previousSubscription->started_at,
            'period_renews_at' => $previousSubscription->renews_at,
            'profiles_remaining' => 1,
            'avatar_images_remaining' => 1,
            'avatar_video_seconds_remaining' => 5,
            'voice_clones_remaining' => 1,
            'tts_characters_remaining' => 10000,
            'chat_messages_remaining' => 1000,
            'credits_remaining' => 1000,
        ]);
        $token = $admin->createToken('test-token', ['user:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->json('PATCH', self::ENDPOINT_USERS.'/'.$user->id.'/subscription', [
                'plan' => 'admin',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'User subscription updated successfully.');
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.subscription.plan', 'admin');
        $response->assertJsonPath('data.subscription.plan_name', 'Admin');
        $response->assertJsonPath('data.subscription.unlimited', true);
        $response->assertJsonPath('data.subscription.billing_mode', 'admin_grant');

        $previousSubscription->refresh();
        $this->assertFalse($previousSubscription->active);
        $this->assertSame(SubscriptionStatus::Expired, $previousSubscription->status);

        $subscription = Subscription::where('user_id', $user->id)
            ->where('active', true)
            ->firstOrFail();
        $this->assertSame(SubscriptionPlan::Admin, $subscription->plan);
        $this->assertSame(SubscriptionStatus::Renewed, $subscription->status);
        $this->assertSame('admin_grant', $subscription->billing_mode);

        $this->assertDatabaseHas('subscription_limits', [
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'profiles_remaining' => 2147483647,
            'avatar_images_remaining' => 2147483647,
            'credits_remaining' => 99999999.99,
        ]);
    }

    public function test_non_admin_user_can_not_assign_user_subscription(): void
    {
        $actor = User::factory()->create(['role' => 'user']);
        $target = User::factory()->create(['role' => 'user']);
        $token = $actor->createToken('test-token', ['admin.users.subscriptions.manage'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->json('PATCH', self::ENDPOINT_USERS.'/'.$target->id.'/subscription', [
                'plan' => 'admin',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Forbidden.');
    }

    public function test_admin_can_impersonate_user_and_receive_user_token(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $token = $admin->createToken('test-token', ['user:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->json('POST', self::ENDPOINT_USERS.'/'.$user->id.'/impersonate');

        $response->assertStatus(200);
        $response->assertJsonPath('data.admin.id', $admin->id);
        $response->assertJsonPath('data.user.id', $user->id);
        $this->assertNotEmpty($response->json('data.access_token'));

        $impersonationToken = $response->json('data.access_token');
        $tokenModel = PersonalAccessToken::find((int) str($impersonationToken)->before('|')->toString());
        $this->assertContains('user:read', $tokenModel?->abilities ?? []);

        $this->app['auth']->forgetGuards();

        $currentUserResponse = $this->flushHeaders()
            ->withToken($impersonationToken)
            ->json('GET', '/api/user');

        $currentUserResponse->assertStatus(200);
        $currentUserResponse->assertJsonPath('data.id', $user->id);
    }

    public function test_non_admin_user_with_admin_users_impersonate_ability_can_not_impersonate_user(): void
    {
        $actor = User::factory()->create(['role' => 'user']);
        $target = User::factory()->create(['role' => 'user']);
        $token = $actor->createToken('test-token', ['admin.users.impersonate'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->json('POST', self::ENDPOINT_USERS.'/'.$target->id.'/impersonate');

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Forbidden.');
    }

    public function test_stop_impersonation_deletes_current_token(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $adminToken = $admin->createToken('test-token', ['user:read'])->plainTextToken;

        $impersonationResponse = $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->json('POST', self::ENDPOINT_USERS.'/'.$user->id.'/impersonate');
        $impersonationToken = $impersonationResponse->json('data.access_token');

        $this->app['auth']->forgetGuards();

        $response = $this->flushHeaders()
            ->withToken($impersonationToken)
            ->json('POST', '/api/admin/impersonation/stop');

        $response->assertStatus(200);

        $this->app['auth']->forgetGuards();

        $currentUserResponse = $this->flushHeaders()
            ->withToken($impersonationToken)
            ->json('GET', '/api/user');

        $currentUserResponse->assertStatus(401);
    }

    public function test_stop_impersonation_does_not_delete_regular_tokens(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $token = $user->createToken('test-token', ['user:read'])->plainTextToken;

        $response = $this->withToken($token)
            ->json('POST', '/api/admin/impersonation/stop');

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Current token is not an admin impersonation token.');

        $currentUserResponse = $this->flushHeaders()
            ->withToken($token)
            ->json('GET', '/api/user');

        $currentUserResponse->assertStatus(200);
        $currentUserResponse->assertJsonPath('data.id', $user->id);
    }

    private function createProfileResources(User $user): Profile
    {
        $profile = Profile::factory()->create(['user_id' => $user->id]);

        ProfileSource::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'type' => ProfileSourceType::Cv,
            'name' => 'CV',
            'status' => ProfileSourceStatus::Parsed,
        ]);

        ProfileAvatar::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'file' => 'avatars/avatar.png',
        ]);

        Voice::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
        ]);

        AiImage::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
        ]);

        AiVideo::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
        ]);

        Chat::create(['profile_id' => $profile->id]);

        return $profile;
    }
}
