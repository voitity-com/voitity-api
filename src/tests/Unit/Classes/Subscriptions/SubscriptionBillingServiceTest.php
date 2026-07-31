<?php

namespace Tests\Unit\Classes\Subscriptions;

use App\Classes\PaymentService\PaymentClient;
use App\Classes\PaymentService\PaymentIntent;
use App\Classes\PaymentService\PaymentPayloadSanitizer;
use App\Classes\PaymentService\PaymentRequest;
use App\Classes\PaymentService\PaymentService;
use App\Classes\PaymentService\PaymentSourceCharge;
use App\Classes\PaymentService\PaymentSourceChargeRequest;
use App\Classes\PaymentService\PaymentSourceCreateRequest;
use App\Classes\PaymentService\PaymentSourceCreateResult;
use App\Classes\PaymentService\PaymentSourceSetup;
use App\Classes\PaymentService\PaymentWebhook;
use App\Classes\Subscriptions\PaymentMethodService;
use App\Classes\Subscriptions\SubscriptionBillingService;
use App\Classes\Subscriptions\SubscriptionPlanActivator;
use App\Classes\Subscriptions\SubscriptionPlanAssigner;
use App\Classes\Subscriptions\SubscriptionPlanCatalog;
use App\Enums\PaymentCurrency;
use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\ProfileStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Subscriptions\SubscriptionPaymentRecoveryException;
use App\Models\PaymentOrder;
use App\Models\PaymentSource;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SubscriptionBillingServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function it_bills_due_recurring_subscription_and_activates_new_subscription(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 00:00:00'));
        config(['payment.usd_cop_rate' => 4000]);

        $user = User::factory()->create(['email' => 'subscriber@example.com']);
        $paymentSource = $this->activePaymentSource($user);
        $subscription = $this->dueSubscription($user, $paymentSource);
        $paymentClient = new FakeRecurringPaymentClient('APPROVED');

        $summary = $this->billingService($paymentClient)->billDueRecurringSubscriptions();

        $this->assertSame([
            'processed' => 1,
            'approved' => 1,
            'pending' => 0,
            'failed' => 0,
            'skipped' => 0,
        ], $summary);

        $paymentOrder = PaymentOrder::query()->firstOrFail();

        $this->assertSame('subscriber@example.com', $paymentClient->charges[0]->customerEmail);
        $this->assertSame('3891', $paymentClient->charges[0]->paymentSourceProviderId);
        $this->assertTrue($paymentClient->charges[0]->recurrent);
        $this->assertSame(5196000, $paymentClient->charges[0]->amountInCents);
        $this->assertSame('COP', $paymentClient->charges[0]->currency);

        $this->assertSame(PaymentOrderStatus::Approved, $paymentOrder->status);
        $this->assertSame('subscription_renewal', $paymentOrder->billing_reason);
        $this->assertTrue($paymentOrder->recurring);
        $this->assertSame($paymentSource->id, $paymentOrder->payment_source_id);
        $this->assertSame(5196000, $paymentOrder->amount_in_cents);
        $this->assertSame('trx_'.$paymentOrder->reference, $paymentOrder->provider_transaction_id);
        $this->assertNotNull($paymentOrder->subscription_id);
        $this->assertNotNull($paymentOrder->paid_at);

        $subscription->refresh();
        $activeSubscription = Subscription::query()
            ->where('user_id', $user->id)
            ->where('active', true)
            ->firstOrFail();

        $this->assertFalse($subscription->active);
        $this->assertSame(SubscriptionStatus::Expired, $subscription->status);
        $this->assertSame(SubscriptionPlan::Starter, $activeSubscription->plan);
        $this->assertSame('recurring', $activeSubscription->billing_mode);
        $this->assertSame($paymentSource->id, $activeSubscription->payment_source_id);
        $this->assertSame($paymentOrder->id, $activeSubscription->source_payment_order_id);
        $this->assertNotNull($activeSubscription->last_billed_at);
        $this->assertNotNull($activeSubscription->next_billing_at);

        $paymentSource->refresh();
        $this->assertNotNull($paymentSource->last_used_at);
    }

    #[Test]
    public function it_suspends_due_subscription_without_chargeable_payment_source(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 00:00:00'));

        $user = User::factory()->create();
        $this->dueSubscription($user);
        $paymentClient = new FakeRecurringPaymentClient('APPROVED');

        $summary = $this->billingService($paymentClient)->billDueRecurringSubscriptions();

        $this->assertSame([
            'processed' => 1,
            'approved' => 0,
            'pending' => 0,
            'failed' => 1,
            'skipped' => 0,
        ], $summary);
        $this->assertCount(0, $paymentClient->charges);
        $this->assertDatabaseCount('payment_orders', 0);
        $subscription = Subscription::query()->firstOrFail();
        $this->assertFalse($subscription->active);
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertSame('payment_method_required', $subscription->payment_failure_code);
        $this->assertSame(1, $subscription->payment_retry_count);
        $this->assertTrue($subscription->next_payment_retry_at->equalTo(now()->addHours(6)));
    }

    #[Test]
    public function it_does_not_duplicate_charge_when_pending_renewal_order_already_exists(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 00:00:00'));

        $user = User::factory()->create();
        $paymentSource = $this->activePaymentSource($user);
        $subscription = $this->dueSubscription($user, $paymentSource);
        $this->pendingRenewalOrder($user, $subscription, $paymentSource);
        $paymentClient = new FakeRecurringPaymentClient('APPROVED');

        $summary = $this->billingService($paymentClient)->billDueRecurringSubscriptions();

        $this->assertSame([
            'processed' => 1,
            'approved' => 0,
            'pending' => 1,
            'failed' => 0,
            'skipped' => 0,
        ], $summary);
        $this->assertCount(0, $paymentClient->charges);
        $this->assertDatabaseCount('payment_orders', 1);
    }

    #[Test]
    public function it_refreshes_pending_recurring_charge_before_finishing_billing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 00:00:00'));
        config([
            'payment.pending_charge_poll_attempts' => 1,
            'payment.pending_charge_poll_delay_ms' => 0,
            'payment.usd_cop_rate' => 4000,
        ]);

        $user = User::factory()->create();
        $paymentSource = $this->activePaymentSource($user);
        $this->dueSubscription($user, $paymentSource);
        $paymentClient = new FakeRecurringPaymentClient('PENDING', 'APPROVED');

        $summary = $this->billingService($paymentClient)->billDueRecurringSubscriptions();

        $this->assertSame([
            'processed' => 1,
            'approved' => 1,
            'pending' => 0,
            'failed' => 0,
            'skipped' => 0,
        ], $summary);
        $this->assertCount(1, $paymentClient->lookups);
        $this->assertSame(PaymentOrderStatus::Approved, PaymentOrder::query()->firstOrFail()->status);
    }

    #[Test]
    public function it_does_not_bill_trial_before_free_period_ends(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 00:00:00'));

        $user = User::factory()->create();
        $paymentSource = $this->activePaymentSource($user);
        $this->trialSubscription($user, $paymentSource, [
            'started_at' => now()->subDays(2),
            'trial_started_at' => now()->subDays(2),
            'trial_ends_at' => now()->addDays(5),
            'renews_at' => now()->addDays(5),
            'next_billing_at' => now()->addDays(5),
        ]);
        $paymentClient = new FakeRecurringPaymentClient('APPROVED');

        $summary = $this->billingService($paymentClient)->billDueRecurringSubscriptions();

        $this->assertSame([
            'processed' => 0,
            'approved' => 0,
            'pending' => 0,
            'failed' => 0,
            'skipped' => 0,
        ], $summary);
        $this->assertCount(0, $paymentClient->charges);
        $this->assertDatabaseCount('payment_orders', 0);
    }

    #[Test]
    public function it_skips_cancelled_trial_at_period_end_without_charging(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 00:00:00'));

        $user = User::factory()->create();
        $paymentSource = $this->activePaymentSource($user);
        $this->trialSubscription($user, $paymentSource, [
            'started_at' => now()->subDays(7),
            'trial_started_at' => now()->subDays(7),
            'trial_ends_at' => now()->subMinute(),
            'renews_at' => now()->subMinute(),
            'next_billing_at' => now()->subMinute(),
            'cancel_at_period_end' => true,
            'cancelled_at' => now()->subDay(),
            'trial_cancelled_at' => now()->subDay(),
        ]);
        $paymentClient = new FakeRecurringPaymentClient('APPROVED');

        $summary = $this->billingService($paymentClient)->billDueRecurringSubscriptions();

        $this->assertSame([
            'processed' => 0,
            'approved' => 0,
            'pending' => 0,
            'failed' => 0,
            'skipped' => 0,
        ], $summary);
        $this->assertCount(0, $paymentClient->charges);
        $this->assertDatabaseCount('payment_orders', 0);
    }

    #[Test]
    public function it_marks_trial_past_due_when_conversion_charge_is_declined(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 00:00:00'));
        config(['payment.usd_cop_rate' => 4000]);

        $user = User::factory()->create();
        $paymentSource = $this->activePaymentSource($user);
        $trial = $this->trialSubscription($user, $paymentSource, [
            'started_at' => now()->subDays(7),
            'trial_started_at' => now()->subDays(7),
            'trial_ends_at' => now()->subMinute(),
            'renews_at' => now()->subMinute(),
            'next_billing_at' => now()->subMinute(),
        ]);
        $paymentClient = new FakeRecurringPaymentClient('DECLINED');

        $summary = $this->billingService($paymentClient)->billDueRecurringSubscriptions();

        $this->assertSame([
            'processed' => 1,
            'approved' => 0,
            'pending' => 0,
            'failed' => 1,
            'skipped' => 0,
        ], $summary);

        $paymentOrder = PaymentOrder::query()->firstOrFail();
        $trial->refresh();

        $this->assertSame(PaymentOrderStatus::Declined, $paymentOrder->status);
        $this->assertSame('trial_conversion', $paymentOrder->billing_reason);
        $this->assertFalse($trial->active);
        $this->assertSame(SubscriptionStatus::PastDue, $trial->status);
    }

    #[Test]
    public function it_charges_annual_amount_when_annual_trial_converts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 00:00:00'));
        config(['payment.usd_cop_rate' => 4000]);

        $user = User::factory()->create();
        $paymentSource = $this->activePaymentSource($user);
        $this->trialSubscription($user, $paymentSource, [
            'plan' => SubscriptionPlan::StarterAnnual,
            'started_at' => now()->subDays(7),
            'trial_started_at' => now()->subDays(7),
            'trial_ends_at' => now()->subMinute(),
            'renews_at' => now()->subMinute(),
            'next_billing_at' => now()->subMinute(),
        ]);
        $paymentClient = new FakeRecurringPaymentClient('APPROVED');

        $summary = $this->billingService($paymentClient)->billDueRecurringSubscriptions();

        $this->assertSame([
            'processed' => 1,
            'approved' => 1,
            'pending' => 0,
            'failed' => 0,
            'skipped' => 0,
        ], $summary);

        $paymentOrder = PaymentOrder::query()->firstOrFail();
        $activeSubscription = Subscription::query()
            ->where('user_id', $user->id)
            ->where('active', true)
            ->firstOrFail();

        $this->assertSame(51600000, $paymentClient->charges[0]->amountInCents);
        $this->assertSame(51600000, $paymentOrder->amount_in_cents);
        $this->assertSame(SubscriptionPlan::StarterAnnual, $activeSubscription->plan);
        $this->assertTrue($activeSubscription->renews_at->isSameDay(now()->copy()->addYear()));
    }

    #[Test]
    public function it_does_not_bill_annual_subscription_when_only_monthly_usage_period_is_due(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-16 00:00:00'));

        $user = User::factory()->create();
        $paymentSource = $this->activePaymentSource($user);

        Subscription::query()->create([
            'user_id' => $user->id,
            'payment_source_id' => $paymentSource->id,
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

        $paymentClient = new FakeRecurringPaymentClient('APPROVED');

        $summary = $this->billingService($paymentClient)->billDueRecurringSubscriptions();

        $this->assertSame([
            'processed' => 0,
            'approved' => 0,
            'pending' => 0,
            'failed' => 0,
            'skipped' => 0,
        ], $summary);
        $this->assertCount(0, $paymentClient->charges);
        $this->assertDatabaseCount('payment_orders', 0);
    }

    #[Test]
    public function it_retries_failed_payment_on_schedule_and_restores_suspended_profile(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 00:00:00'));
        config([
            'payment.usd_cop_rate' => 4000,
            'subscriptions.payment_retry_hours' => [6, 24, 72],
        ]);

        $user = User::factory()->create();
        $paymentSource = $this->activePaymentSource($user);
        $subscription = $this->dueSubscription($user, $paymentSource);
        $profile = Profile::factory()->create([
            'user_id' => $user->id,
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);

        $firstSummary = $this->billingService(new FakeRecurringPaymentClient('DECLINED'))
            ->billDueRecurringSubscriptions();

        $this->assertSame(1, $firstSummary['failed']);
        $subscription->refresh();
        $profile->refresh();
        $this->assertFalse($subscription->active);
        $this->assertSame(1, $subscription->payment_retry_count);
        $this->assertFalse($profile->active);
        $this->assertSame($subscription->id, $profile->suspended_by_subscription_id);
        $this->assertSame(ProfileStatus::Published->value, $profile->subscription_suspension_previous_status);
        $paymentSource->refresh();
        $this->assertTrue($paymentSource->requires_attention);
        $this->assertFalse($paymentSource->isChargeable());
        $this->assertTrue($paymentSource->canAttemptCharge());

        Carbon::setTestNow('2026-07-09 05:59:00');
        $earlySummary = $this->billingService(new FakeRecurringPaymentClient('APPROVED'))
            ->billDueRecurringSubscriptions();
        $this->assertSame(0, $earlySummary['processed']);

        Carbon::setTestNow('2026-07-09 06:00:00');
        $retrySummary = $this->billingService(new FakeRecurringPaymentClient('APPROVED'))
            ->billDueRecurringSubscriptions();

        $this->assertSame(1, $retrySummary['approved']);
        $profile->refresh();
        $this->assertTrue($profile->active);
        $this->assertSame(ProfileStatus::Published, $profile->status);
        $this->assertNull($profile->subscription_suspended_at);
        $this->assertNull($profile->suspended_by_subscription_id);
        $paymentSource->refresh();
        $this->assertFalse($paymentSource->requires_attention);
        $this->assertTrue($paymentSource->isChargeable());
        $this->assertDatabaseHas('payment_orders', [
            'source_subscription_id' => $subscription->id,
            'attempt_number' => 2,
            'status' => PaymentOrderStatus::Approved->value,
        ]);
    }

    #[Test]
    public function it_stops_automatic_retries_after_configured_attempts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 00:00:00'));
        config(['subscriptions.payment_retry_hours' => [6, 24, 72]]);

        $user = User::factory()->create();
        $paymentSource = $this->activePaymentSource($user);
        $subscription = $this->dueSubscription($user, $paymentSource);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $summary = $this->billingService(new FakeRecurringPaymentClient('DECLINED'))
                ->billDueRecurringSubscriptions();
            $this->assertSame(1, $summary['failed']);
            $subscription->refresh();
            $this->assertSame($attempt, $subscription->payment_retry_count);

            if ($subscription->next_payment_retry_at) {
                Carbon::setTestNow($subscription->next_payment_retry_at);
            }
        }

        $this->assertNull($subscription->fresh()->next_payment_retry_at);
        Carbon::setTestNow(now()->addWeek());
        $summary = $this->billingService(new FakeRecurringPaymentClient('APPROVED'))
            ->billDueRecurringSubscriptions();
        $this->assertSame(0, $summary['processed']);
        $this->assertDatabaseCount('payment_orders', 4);
    }

    #[Test]
    public function it_allows_immediate_manual_retry_and_blocks_a_new_card_while_old_order_is_pending(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 00:00:00'));

        $user = User::factory()->create();
        $oldSource = $this->activePaymentSource($user);
        $subscription = $this->dueSubscription($user, $oldSource);
        $pendingOrder = $this->pendingRenewalOrder($user, $subscription, $oldSource);
        $subscription->forceFill([
            'active' => false,
            'status' => SubscriptionStatus::PastDue,
            'payment_failure_code' => 'payment_declined',
            'payment_failed_at' => now(),
            'payment_retry_count' => 1,
            'next_payment_retry_at' => now()->addHours(6),
            'access_ended_reason' => 'payment_failure',
        ])->save();
        $newSource = PaymentSource::query()->create([
            'user_id' => $user->id,
            'provider' => PaymentProvider::Wompi,
            'provider_source_id' => 'new-source-5000',
            'type' => 'CARD',
            'status' => 'active',
            'reusable' => true,
            'verified_at' => now(),
        ]);
        $oldSource->forceFill(['is_default' => false])->save();
        $newSource->forceFill(['is_default' => true])->save();
        $client = new FakeRecurringPaymentClient('APPROVED');

        $result = $this->billingService($client)->retryFailedRenewal($user);

        $this->assertSame('pending', $result['outcome']);
        $this->assertSame($pendingOrder->id, $result['order']?->id);
        $this->assertCount(0, $client->charges);
        $this->assertDatabaseCount('payment_orders', 1);
    }

    #[Test]
    public function it_requires_a_replacement_after_a_decline_and_renews_immediately_with_the_new_default_card(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 00:00:00'));
        config(['payment.usd_cop_rate' => 4000]);

        $user = User::factory()->create();
        $rejectedSource = $this->activePaymentSource($user);
        $subscription = $this->dueSubscription($user, $rejectedSource);
        $profile = Profile::factory()->create([
            'user_id' => $user->id,
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);

        $declined = $this->billingService(new FakeRecurringPaymentClient('DECLINED'))
            ->billDueRecurringSubscriptions();

        $this->assertSame(1, $declined['failed']);
        $rejectedSource->refresh();
        $profile->refresh();
        $this->assertTrue($rejectedSource->requires_attention);
        $this->assertFalse($rejectedSource->isChargeable());
        $this->assertFalse($profile->active);

        try {
            $this->billingService(new FakeRecurringPaymentClient('APPROVED'))
                ->retryFailedRenewal($user);
            $this->fail('The rejected default source should not support a manual renewal.');
        } catch (SubscriptionPaymentRecoveryException $exception) {
            $this->assertSame('PAYMENT_METHOD_REQUIRED', $exception->errorCode());
        }

        $replacementSource = PaymentSource::query()->create([
            'user_id' => $user->id,
            'provider' => PaymentProvider::Wompi,
            'provider_source_id' => 'replacement-source-5001',
            'type' => 'CARD',
            'status' => 'active',
            'reusable' => true,
            'verified_at' => now(),
        ]);
        $rejectedSource->forceFill(['is_default' => false])->save();
        $replacementSource->forceFill(['is_default' => true])->save();

        $approvedClient = new FakeRecurringPaymentClient('APPROVED');
        $result = $this->billingService($approvedClient)->retryFailedRenewal($user);

        $this->assertSame('approved', $result['outcome']);
        $this->assertCount(1, $approvedClient->charges);
        $this->assertSame(
            $replacementSource->provider_source_id,
            $approvedClient->charges[0]->paymentSourceProviderId,
        );
        $this->assertSame($replacementSource->id, $result['subscription']?->payment_source_id);

        $rejectedSource->refresh();
        $replacementSource->refresh();
        $profile->refresh();
        $subscription->refresh();

        $this->assertTrue($rejectedSource->requires_attention);
        $this->assertFalse($rejectedSource->is_default);
        $this->assertFalse($replacementSource->requires_attention);
        $this->assertTrue($replacementSource->is_default);
        $this->assertTrue($profile->active);
        $this->assertSame(ProfileStatus::Published, $profile->status);
        $this->assertNull($profile->suspended_by_subscription_id);
        $this->assertFalse($subscription->active);
        $this->assertSame(SubscriptionStatus::Expired, $subscription->status);
    }

    private function billingService(FakeRecurringPaymentClient $paymentClient): SubscriptionBillingService
    {
        $planCatalog = new SubscriptionPlanCatalog;
        $planAssigner = new SubscriptionPlanAssigner;
        $paymentService = new PaymentService($paymentClient);
        $payloadSanitizer = new PaymentPayloadSanitizer;
        $paymentMethods = new PaymentMethodService($paymentService, $payloadSanitizer);

        return new SubscriptionBillingService(
            $paymentService,
            $planCatalog,
            new SubscriptionPlanActivator($planAssigner, $paymentMethods),
            $paymentMethods,
            $payloadSanitizer,
        );
    }

    private function activePaymentSource(User $user): PaymentSource
    {
        return PaymentSource::query()->create([
            'user_id' => $user->id,
            'provider' => PaymentProvider::Wompi,
            'provider_source_id' => '3891',
            'type' => 'CARD',
            'status' => 'active',
            'reusable' => true,
            'verified_at' => now(),
        ]);
    }

    private function dueSubscription(User $user, ?PaymentSource $paymentSource = null): Subscription
    {
        return Subscription::query()->create([
            'user_id' => $user->id,
            'payment_source_id' => $paymentSource?->id,
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
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function trialSubscription(User $user, PaymentSource $paymentSource, array $overrides = []): Subscription
    {
        return Subscription::query()->create(array_merge([
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
        ], $overrides));
    }

    private function pendingRenewalOrder(User $user, Subscription $subscription, PaymentSource $paymentSource): PaymentOrder
    {
        return PaymentOrder::query()->create([
            'user_id' => $user->id,
            'source_subscription_id' => $subscription->id,
            'payment_source_id' => $paymentSource->id,
            'provider' => PaymentProvider::Wompi,
            'reference' => 'VOI-REN-EXISTING',
            'plan' => $subscription->plan,
            'recurring' => true,
            'billing_reason' => 'subscription_renewal',
            'display_amount_usd' => 12.99,
            'display_currency' => PaymentCurrency::Usd,
            'exchange_rate' => 4000,
            'amount_cop' => 51960,
            'amount_in_cents' => 5196000,
            'currency' => PaymentCurrency::Cop,
            'status' => PaymentOrderStatus::Pending,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

class FakeRecurringPaymentClient implements PaymentClient
{
    /**
     * @var array<int, PaymentSourceChargeRequest>
     */
    public array $charges = [];

    /**
     * @var array<int, string>
     */
    public array $lookups = [];

    public function __construct(
        private readonly string $providerStatus,
        private readonly ?string $lookupProviderStatus = null,
    ) {}

    public function createPayment(PaymentRequest $request): PaymentIntent
    {
        throw new RuntimeException('Checkout creation is not used by recurring billing tests.');
    }

    public function paymentSourceSetup(): PaymentSourceSetup
    {
        throw new RuntimeException('Payment source setup is not used by recurring billing tests.');
    }

    public function createPaymentSource(PaymentSourceCreateRequest $request): PaymentSourceCreateResult
    {
        throw new RuntimeException('Payment source creation is not used by recurring billing tests.');
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
            status: $this->statusFor($this->providerStatus),
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
        $providerStatus = $this->lookupProviderStatus ?? $this->providerStatus;
        $this->lookups[] = $providerTransactionId;

        return new PaymentSourceCharge(
            source: 'wompi',
            reference: $reference,
            amountInCents: $amountInCents,
            currency: $currency,
            providerTransactionId: $providerTransactionId,
            providerStatus: $providerStatus,
            status: $this->statusFor($providerStatus),
            httpStatus: 200,
            rawResponse: [
                'data' => [
                    'reference' => $reference,
                    'status' => $providerStatus,
                ],
            ],
        );
    }

    public function parseWebhook(array $headers, string $payload): PaymentWebhook
    {
        throw new RuntimeException('Webhook parsing is not used by recurring billing tests.');
    }

    private function statusFor(string $providerStatus): string
    {
        return match ($providerStatus) {
            'APPROVED' => 'approved',
            'DECLINED' => 'declined',
            'VOIDED' => 'voided',
            'ERROR' => 'error',
            'EXPIRED' => 'expired',
            default => 'pending',
        };
    }
}
