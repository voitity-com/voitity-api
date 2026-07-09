<?php

namespace Tests\Unit\Classes\Subscriptions;

use App\Classes\PaymentService\PaymentClient;
use App\Classes\PaymentService\PaymentIntent;
use App\Classes\PaymentService\PaymentRequest;
use App\Classes\PaymentService\PaymentService;
use App\Classes\PaymentService\PaymentSourceCharge;
use App\Classes\PaymentService\PaymentSourceChargeRequest;
use App\Classes\PaymentService\PaymentWebhook;
use App\Classes\Subscriptions\SubscriptionBillingService;
use App\Classes\Subscriptions\SubscriptionPlanActivator;
use App\Classes\Subscriptions\SubscriptionPlanAssigner;
use App\Classes\Subscriptions\SubscriptionPlanCatalog;
use App\Enums\PaymentCurrency;
use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\PaymentOrder;
use App\Models\PaymentSource;
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
        $this->assertSame(3200000, $paymentClient->charges[0]->amountInCents);
        $this->assertSame('COP', $paymentClient->charges[0]->currency);

        $this->assertSame(PaymentOrderStatus::Approved, $paymentOrder->status);
        $this->assertSame('subscription_renewal', $paymentOrder->billing_reason);
        $this->assertTrue($paymentOrder->recurring);
        $this->assertSame($paymentSource->id, $paymentOrder->payment_source_id);
        $this->assertSame(3200000, $paymentOrder->amount_in_cents);
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
    }

    #[Test]
    public function it_skips_due_subscription_without_chargeable_payment_source(): void
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
            'failed' => 0,
            'skipped' => 1,
        ], $summary);
        $this->assertCount(0, $paymentClient->charges);
        $this->assertDatabaseCount('payment_orders', 0);
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
            'pending' => 0,
            'failed' => 0,
            'skipped' => 1,
        ], $summary);
        $this->assertCount(0, $paymentClient->charges);
        $this->assertDatabaseCount('payment_orders', 1);
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

    private function billingService(FakeRecurringPaymentClient $paymentClient): SubscriptionBillingService
    {
        $planCatalog = new SubscriptionPlanCatalog;
        $planAssigner = new SubscriptionPlanAssigner;

        return new SubscriptionBillingService(
            new PaymentService($paymentClient),
            $planCatalog,
            new SubscriptionPlanActivator($planAssigner),
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

    private function pendingRenewalOrder(User $user, Subscription $subscription, PaymentSource $paymentSource): PaymentOrder
    {
        return PaymentOrder::query()->create([
            'user_id' => $user->id,
            'payment_source_id' => $paymentSource->id,
            'provider' => PaymentProvider::Wompi,
            'reference' => 'VOI-REN-EXISTING',
            'plan' => $subscription->plan,
            'recurring' => true,
            'billing_reason' => 'subscription_renewal',
            'display_amount_usd' => 8,
            'display_currency' => PaymentCurrency::Usd,
            'exchange_rate' => 4000,
            'amount_cop' => 32000,
            'amount_in_cents' => 3200000,
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

    public function __construct(private readonly string $providerStatus) {}

    public function createPayment(PaymentRequest $request): PaymentIntent
    {
        throw new RuntimeException('Checkout creation is not used by recurring billing tests.');
    }

    public function chargePaymentSource(PaymentSourceChargeRequest $request): PaymentSourceCharge
    {
        $this->charges[] = $request;
        $status = match ($this->providerStatus) {
            'APPROVED' => 'approved',
            'DECLINED' => 'declined',
            'VOIDED' => 'voided',
            'ERROR' => 'error',
            'EXPIRED' => 'expired',
            default => 'pending',
        };

        return new PaymentSourceCharge(
            source: 'wompi',
            reference: $request->reference,
            amountInCents: $request->amountInCents,
            currency: $request->currency,
            providerTransactionId: 'trx_'.$request->reference,
            providerStatus: $this->providerStatus,
            status: $status,
            httpStatus: 201,
            rawResponse: [
                'data' => [
                    'reference' => $request->reference,
                    'status' => $this->providerStatus,
                ],
            ],
        );
    }

    public function parseWebhook(array $headers, string $payload): PaymentWebhook
    {
        throw new RuntimeException('Webhook parsing is not used by recurring billing tests.');
    }
}
