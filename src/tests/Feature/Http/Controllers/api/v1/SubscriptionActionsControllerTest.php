<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Enums\PaymentProvider;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\PaymentSource;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;

class SubscriptionActionsControllerTest extends TestAPI
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_user_can_cancel_trial_without_losing_current_access(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 10:00:00'));

        $user = User::factory()->create();
        $subscription = $this->trialSubscription($user);
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/subscription/trial/cancel');

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Subscription trial cancellation scheduled.');
        $response->assertJsonPath('data.subscription.id', $subscription->id);
        $response->assertJsonPath('data.subscription.cancel_at_period_end', true);

        $subscription->refresh();

        $this->assertTrue($subscription->active);
        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertNotNull($subscription->cancelled_at);
        $this->assertNotNull($subscription->trial_cancelled_at);
    }

    public function test_user_can_cancel_paid_subscription_automatic_renewal(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 10:00:00'));

        $user = User::factory()->create();
        $subscription = $this->paidSubscription($user);
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/subscription/renewal/cancel');

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Subscription renewal cancellation scheduled.');
        $response->assertJsonPath('data.subscription.id', $subscription->id);
        $response->assertJsonPath('data.subscription.cancel_at_period_end', true);

        $subscription->refresh();

        $this->assertTrue($subscription->active);
        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertNotNull($subscription->cancelled_at);
        $this->assertNull($subscription->trial_cancelled_at);
    }

    public function test_user_can_reactivate_cancelled_automatic_renewal(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 10:00:00'));

        $user = User::factory()->create();
        $subscription = $this->paidSubscription($user, [
            'cancel_at_period_end' => true,
            'cancelled_at' => now()->subHour(),
        ]);
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/subscription/renewal/reactivate');

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Subscription renewal reactivated.');
        $response->assertJsonPath('data.subscription.id', $subscription->id);
        $response->assertJsonPath('data.subscription.cancel_at_period_end', false);

        $subscription->refresh();

        $this->assertTrue($subscription->active);
        $this->assertFalse($subscription->cancel_at_period_end);
        $this->assertNull($subscription->cancelled_at);
    }

    public function test_billing_state_exposes_only_payment_failure_recovery_actions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 10:00:00'));

        $user = User::factory()->create();
        $subscription = $this->paidSubscription($user, [
            'active' => false,
            'status' => SubscriptionStatus::PastDue,
            'payment_failure_code' => 'payment_declined',
            'payment_failed_at' => now()->subHour(),
            'payment_retry_count' => 1,
            'next_payment_retry_at' => now()->addHours(5),
            'access_ended_reason' => 'payment_failure',
        ]);
        $token = $user->createToken('test-token', ['payments:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/subscription/billing-state')
            ->assertOk()
            ->assertJsonPath('data.subscription.id', $subscription->id)
            ->assertJsonPath('data.subscription.status', 'past_due')
            ->assertJsonPath('data.payment_recovery.required', true)
            ->assertJsonPath('data.payment_recovery.reason_code', 'payment_declined')
            ->assertJsonPath('data.payment_recovery.retry_count', 1)
            ->assertJsonPath('data.payment_recovery.automatic_retries_remaining', 3)
            ->assertJsonPath('data.payment_recovery.can_retry_now', true)
            ->assertJsonPath('data.payment_method.is_chargeable', true);
    }

    public function test_manual_retry_requires_a_chargeable_default_payment_method(): void
    {
        $user = User::factory()->create();
        $subscription = $this->paidSubscription($user, [
            'active' => false,
            'status' => SubscriptionStatus::PastDue,
            'payment_failure_code' => 'payment_method_required',
            'payment_failed_at' => now()->subHour(),
            'payment_retry_count' => 1,
            'next_payment_retry_at' => now()->addHours(5),
            'access_ended_reason' => 'payment_failure',
        ]);
        $subscription->paymentSource->forceFill([
            'disabled_at' => now(),
            'is_default' => false,
            'reusable' => false,
            'status' => 'disabled',
        ])->save();
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/subscription/renewal/retry')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PAYMENT_METHOD_REQUIRED');
    }

    public function test_billing_state_blocks_manual_retry_when_the_default_card_was_declined(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 10:00:00'));

        $user = User::factory()->create();
        $subscription = $this->paidSubscription($user, [
            'active' => false,
            'status' => SubscriptionStatus::PastDue,
            'payment_failure_code' => 'payment_declined',
            'payment_failed_at' => now()->subHour(),
            'payment_retry_count' => 1,
            'next_payment_retry_at' => now()->addHours(5),
            'access_ended_reason' => 'payment_failure',
        ]);
        $subscription->paymentSource->forceFill([
            'requires_attention' => true,
            'last_payment_failure_code' => 'payment_declined',
            'last_payment_failed_at' => now()->subHour(),
        ])->save();
        $token = $user->createToken('test-token', ['payments:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/subscription/billing-state')
            ->assertOk()
            ->assertJsonPath('data.payment_recovery.required', true)
            ->assertJsonPath('data.payment_recovery.can_retry_now', false)
            ->assertJsonPath('data.payment_method.requires_attention', true)
            ->assertJsonPath('data.payment_method.is_chargeable', false)
            ->assertJsonPath('data.payment_method.last_failure.code', 'payment_declined');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function trialSubscription(User $user, array $overrides = []): Subscription
    {
        $paymentSource = $this->paymentSource($user);
        $startedAt = now()->subDay();
        $renewsAt = now()->addDays(6);

        return Subscription::query()->create(array_merge([
            'user_id' => $user->id,
            'payment_source_id' => $paymentSource->id,
            'plan' => SubscriptionPlan::Starter,
            'billing_mode' => 'recurring',
            'started_at' => $startedAt,
            'trial_started_at' => $startedAt,
            'trial_ends_at' => $renewsAt,
            'renews_at' => $renewsAt,
            'status' => SubscriptionStatus::Trialing,
            'active' => true,
            'cancel_at_period_end' => false,
            'next_billing_at' => $renewsAt,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function paidSubscription(User $user, array $overrides = []): Subscription
    {
        $paymentSource = $this->paymentSource($user);
        $startedAt = now()->subWeek();
        $renewsAt = now()->addWeeks(3);

        return Subscription::query()->create(array_merge([
            'user_id' => $user->id,
            'payment_source_id' => $paymentSource->id,
            'plan' => SubscriptionPlan::Starter,
            'billing_mode' => 'recurring',
            'started_at' => $startedAt,
            'renews_at' => $renewsAt,
            'status' => SubscriptionStatus::First,
            'active' => true,
            'cancel_at_period_end' => false,
            'last_billed_at' => $startedAt,
            'next_billing_at' => $renewsAt,
        ], $overrides));
    }

    private function paymentSource(User $user): PaymentSource
    {
        return PaymentSource::query()->create([
            'user_id' => $user->id,
            'provider' => PaymentProvider::Wompi,
            'provider_source_id' => 'ps_'.$user->id,
            'type' => 'CARD',
            'status' => 'active',
            'reusable' => true,
            'verified_at' => now(),
        ]);
    }
}
