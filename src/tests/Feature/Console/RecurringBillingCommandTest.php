<?php

namespace Tests\Feature\Console;

use App\Classes\PaymentService\PaymentClient;
use App\Classes\PaymentService\PaymentIntent;
use App\Classes\PaymentService\PaymentRequest;
use App\Classes\PaymentService\PaymentSourceCreateRequest;
use App\Classes\PaymentService\PaymentSourceCreateResult;
use App\Classes\PaymentService\PaymentSourceCharge;
use App\Classes\PaymentService\PaymentSourceChargeRequest;
use App\Classes\PaymentService\PaymentSourceSetup;
use App\Classes\PaymentService\PaymentWebhook;
use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\PaymentOrder;
use App\Models\PaymentSource;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\User;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class RecurringBillingCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function it_runs_recurring_billing_job_from_command(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 00:00:00'));
        config(['payment.usd_cop_rate' => 4000]);

        $paymentClient = new ConsoleRecurringPaymentClient('APPROVED');
        $this->app->instance(PaymentClient::class, $paymentClient);

        $user = User::factory()->create(['email' => 'command@example.com']);
        $paymentSource = PaymentSource::query()->create([
            'user_id' => $user->id,
            'provider' => PaymentProvider::Wompi,
            'provider_source_id' => '3891',
            'type' => 'CARD',
            'status' => 'active',
            'reusable' => true,
            'verified_at' => now(),
        ]);

        Subscription::query()->create([
            'user_id' => $user->id,
            'payment_source_id' => $paymentSource->id,
            'plan' => SubscriptionPlan::Starter,
            'billing_mode' => 'recurring',
            'started_at' => now()->subMonth(),
            'renews_at' => now()->subMinute(),
            'status' => SubscriptionStatus::First,
            'active' => true,
            'cancel_at_period_end' => false,
            'last_billed_at' => now()->subMonth(),
            'next_billing_at' => now()->subMinute(),
        ]);

        $this->artisan('subscriptions:bill-recurring')
            ->expectsOutput('Recurring billing processed: 1. Approved: 1. Pending: 0. Failed: 0. Skipped: 0.')
            ->assertSuccessful();

        $this->assertCount(1, $paymentClient->charges);
        $this->assertDatabaseHas('payment_orders', [
            'user_id' => $user->id,
            'payment_source_id' => $paymentSource->id,
            'plan' => 'starter',
            'recurring' => true,
            'billing_reason' => 'subscription_renewal',
            'status' => PaymentOrderStatus::Approved->value,
        ]);

        $paymentOrder = PaymentOrder::query()->firstOrFail();

        $this->assertNotNull($paymentOrder->subscription_id);
    }

    #[Test]
    public function it_runs_usage_limit_period_reset_from_command(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-16 00:00:00'));

        $user = User::factory()->create(['email' => 'usage-reset@example.com']);
        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'plan' => SubscriptionPlan::StarterAnnual,
            'billing_mode' => 'recurring',
            'started_at' => Carbon::parse('2026-01-15 00:00:00'),
            'renews_at' => Carbon::parse('2027-01-15 00:00:00'),
            'status' => SubscriptionStatus::First,
            'active' => true,
            'cancel_at_period_end' => false,
            'last_billed_at' => Carbon::parse('2026-01-15 00:00:00'),
            'next_billing_at' => Carbon::parse('2027-01-15 00:00:00'),
        ]);

        SubscriptionLimit::query()->create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'period_started_at' => Carbon::parse('2026-01-15 00:00:00'),
            'period_renews_at' => Carbon::parse('2026-02-15 00:00:00'),
            'profiles_remaining' => 0,
            'avatar_images_remaining' => 0,
            'avatar_video_seconds_remaining' => 0,
            'voice_clones_remaining' => 0,
            'tts_characters_remaining' => 0,
            'chat_messages_remaining' => 0,
            'credits_remaining' => 0,
        ]);

        $this->artisan('subscriptions:reset-usage-limits')
            ->expectsOutput('Subscription usage limit periods reset: 1')
            ->assertSuccessful();

        $limit = $subscription->limit()->firstOrFail();

        $this->assertTrue($limit->period_started_at->isSameDay(Carbon::parse('2026-02-15')));
        $this->assertTrue($limit->period_renews_at->isSameDay(Carbon::parse('2026-03-15')));
        $this->assertSame(1, $limit->profiles_remaining);
    }

    #[Test]
    public function it_converts_due_trial_to_paid_subscription_and_resets_usage_limits(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 00:00:00'));
        config(['payment.usd_cop_rate' => 4000]);

        $paymentClient = new ConsoleRecurringPaymentClient('APPROVED');
        $this->app->instance(PaymentClient::class, $paymentClient);

        $user = User::factory()->create(['email' => 'trial-conversion@example.com']);
        $paymentSource = PaymentSource::query()->create([
            'user_id' => $user->id,
            'provider' => PaymentProvider::Wompi,
            'provider_source_id' => '3891',
            'type' => 'CARD',
            'status' => 'active',
            'reusable' => true,
            'verified_at' => now(),
        ]);
        $trial = Subscription::query()->create([
            'user_id' => $user->id,
            'payment_source_id' => $paymentSource->id,
            'plan' => SubscriptionPlan::Starter,
            'billing_mode' => 'recurring',
            'started_at' => now()->subDays(7),
            'trial_started_at' => now()->subDays(7),
            'trial_ends_at' => now()->subMinute(),
            'renews_at' => now()->subMinute(),
            'status' => SubscriptionStatus::Trialing,
            'active' => true,
            'cancel_at_period_end' => false,
            'next_billing_at' => now()->subMinute(),
        ]);

        SubscriptionLimit::query()->create([
            'subscription_id' => $trial->id,
            'user_id' => $user->id,
            'period_started_at' => $trial->started_at,
            'period_renews_at' => $trial->renews_at,
            'profiles_remaining' => 0,
            'avatar_images_remaining' => 0,
            'avatar_video_seconds_remaining' => 0,
            'voice_clones_remaining' => 0,
            'tts_characters_remaining' => 0,
            'chat_messages_remaining' => 0,
            'credits_remaining' => 0,
        ]);

        $this->artisan('subscriptions:bill-recurring')
            ->expectsOutput('Recurring billing processed: 1. Approved: 1. Pending: 0. Failed: 0. Skipped: 0.')
            ->assertSuccessful();

        $this->assertCount(1, $paymentClient->charges);
        $this->assertDatabaseHas('payment_orders', [
            'user_id' => $user->id,
            'payment_source_id' => $paymentSource->id,
            'plan' => 'starter',
            'recurring' => true,
            'billing_reason' => 'trial_conversion',
            'status' => PaymentOrderStatus::Approved->value,
        ]);

        $paymentOrder = PaymentOrder::query()->where('billing_reason', 'trial_conversion')->firstOrFail();
        $trial->refresh();
        $activeSubscription = Subscription::query()
            ->where('user_id', $user->id)
            ->where('active', true)
            ->firstOrFail();
        $activeLimit = $activeSubscription->limit()->firstOrFail();

        $this->assertFalse($trial->active);
        $this->assertSame(SubscriptionStatus::Expired, $trial->status);
        $this->assertNotNull($trial->trial_converted_at);
        $this->assertSame($paymentOrder->id, $activeSubscription->source_payment_order_id);
        $this->assertSame(1000.0, (float) $activeLimit->credits_remaining);
        $this->assertSame(1, $activeLimit->profiles_remaining);
    }
}

class ConsoleRecurringPaymentClient implements PaymentClient
{
    /**
     * @var array<int, PaymentSourceChargeRequest>
     */
    public array $charges = [];

    public function __construct(private readonly string $providerStatus) {}

    public function createPayment(PaymentRequest $request): PaymentIntent
    {
        throw new RuntimeException('Checkout creation is not used by recurring billing command tests.');
    }

    public function paymentSourceSetup(): PaymentSourceSetup
    {
        throw new RuntimeException('Payment source setup is not used by recurring billing command tests.');
    }

    public function createPaymentSource(PaymentSourceCreateRequest $request): PaymentSourceCreateResult
    {
        throw new RuntimeException('Payment source creation is not used by recurring billing command tests.');
    }

    public function chargePaymentSource(PaymentSourceChargeRequest $request): PaymentSourceCharge
    {
        $this->charges[] = $request;

        return new PaymentSourceCharge(
            source: 'wompi',
            reference: $request->reference,
            amountInCents: $request->amountInCents,
            currency: $request->currency,
            providerTransactionId: 'trx_'.$request->reference,
            providerStatus: $this->providerStatus,
            status: $this->providerStatus === 'APPROVED' ? 'approved' : 'pending',
            httpStatus: 201,
            rawResponse: [
                'data' => [
                    'reference' => $request->reference,
                    'status' => $this->providerStatus,
                ],
            ],
        );
    }

    public function getPaymentSourceCharge(
        string $providerTransactionId,
        string $reference,
        int $amountInCents,
        string $currency,
    ): PaymentSourceCharge {
        return new PaymentSourceCharge(
            source: 'wompi',
            reference: $reference,
            amountInCents: $amountInCents,
            currency: $currency,
            providerTransactionId: $providerTransactionId,
            providerStatus: $this->providerStatus,
            status: $this->providerStatus === 'APPROVED' ? 'approved' : 'pending',
            httpStatus: 200,
            rawResponse: [
                'data' => [
                    'reference' => $reference,
                    'status' => $this->providerStatus,
                ],
            ],
        );
    }

    public function parseWebhook(array $headers, string $payload): PaymentWebhook
    {
        throw new RuntimeException('Webhook parsing is not used by recurring billing command tests.');
    }
}
