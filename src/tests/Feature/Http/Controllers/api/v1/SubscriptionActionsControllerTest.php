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
