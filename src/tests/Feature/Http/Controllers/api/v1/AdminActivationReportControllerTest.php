<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Enums\ActivationEventType;
use App\Enums\ProfileStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\ActivationEvent;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;

class AdminActivationReportControllerTest extends TestAPI
{
    public function test_reports_ability_is_only_configured_for_admins(): void
    {
        $this->assertContains('admin.reports.view', config('roles.admin.abilities'));

        foreach (['user', 'profile', 'api'] as $role) {
            $this->assertNotContains('admin.reports.view', config("roles.{$role}.abilities"));
        }
    }

    public function test_non_admin_can_not_read_activation_reports(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $token = $user->createToken('reports', ['admin.reports.view'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/reports/activation')
            ->assertForbidden()
            ->assertJsonPath('message', 'Forbidden.');
    }

    public function test_admin_can_read_funnel_campaign_and_conversion_metrics(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create([
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);
        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'plan' => SubscriptionPlan::Starter,
            'billing_mode' => 'recurring',
            'started_at' => now()->subDays(4),
            'trial_started_at' => now()->subDays(4),
            'trial_ends_at' => now()->addDays(3),
            'trial_converted_at' => now()->subDay(),
            'renews_at' => now()->addMonth(),
            'status' => SubscriptionStatus::Renewed,
            'active' => true,
        ]);
        $events = [
            ActivationEventType::TrialStarted,
            ActivationEventType::ProfileCreated,
            ActivationEventType::AvatarAdded,
            ActivationEventType::SourceSynchronized,
            ActivationEventType::ProfilePublished,
            ActivationEventType::WhatsappAdded,
            ActivationEventType::ProductCreated,
            ActivationEventType::ConversationStarted,
            ActivationEventType::LinkCopied,
        ];

        foreach ($events as $offset => $type) {
            ActivationEvent::query()->create([
                'user_id' => $user->id,
                'profile_id' => $profile->id,
                'subscription_id' => $subscription->id,
                'event_type' => $type,
                'utm_source' => $type === ActivationEventType::TrialStarted ? 'instagram' : null,
                'utm_medium' => $type === ActivationEventType::TrialStarted ? 'paid-social' : null,
                'utm_campaign' => $type === ActivationEventType::TrialStarted ? 'fitness-coaches-co' : null,
                'occurred_at' => now()->subDays(4)->addHours($offset),
                'idempotency_key' => "test:{$user->id}:{$type->value}",
            ]);
        }

        $token = $admin->createToken('reports', ['user:read'])->plainTextToken;
        $response = $this->withToken($token)
            ->getJson('/api/admin/reports/activation?campaign=fitness-coaches-co')
            ->assertOk();

        $response->assertJsonPath('data.overview.trials_started', 1);
        $response->assertJsonPath('data.overview.users_activated', 1);
        $response->assertJsonPath('data.overview.converted_to_paid', 1);
        $response->assertJsonPath('data.overview.activation_rate', 100);
        $response->assertJsonPath('data.funnel.8.event', ActivationEventType::LinkCopied->value);
        $response->assertJsonPath('data.funnel.8.users', 1);
        $response->assertJsonPath('data.campaigns.0.campaign', 'fitness-coaches-co');
        $response->assertJsonPath('data.campaigns.0.converted_to_paid', 1);
    }

    public function test_admin_can_list_activation_users_with_progress(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['name' => 'Fitness Coach', 'email' => 'coach@example.com']);
        $profile = Profile::factory()->for($user)->create();
        ActivationEvent::query()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'event_type' => ActivationEventType::TrialStarted,
            'occurred_at' => now(),
            'idempotency_key' => "test:{$user->id}:trial",
        ]);
        ActivationEvent::query()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'event_type' => ActivationEventType::ProfileCreated,
            'occurred_at' => now(),
            'idempotency_key' => "test:{$user->id}:profile",
        ]);
        $token = $admin->createToken('reports', ['user:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/reports/activation/users?search=coach')
            ->assertOk()
            ->assertJsonPath('data.users.0.id', $user->id)
            ->assertJsonPath('data.users.0.last_event', ActivationEventType::ProfileCreated->value)
            ->assertJsonPath('data.users.0.next_step', ActivationEventType::AvatarAdded->value)
            ->assertJsonPath('data.users.0.activated', false)
            ->assertJsonPath('data.pagination.total', 1);
    }
}
