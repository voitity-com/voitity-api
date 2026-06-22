<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\SubscriptionPlan;
use App\Models\PaymentEvent;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Config;

class PaymentControllerTest extends TestAPI
{
    private const CHECKOUT_ENDPOINT = '/api/payments/wompi/checkout';

    private const PLANS_ENDPOINT = '/api/subscription/plans';

    private const WOMPI_EVENTS_ENDPOINT = '/api/payments/wompi/events';

    public function setUp(): void
    {
        parent::setUp();

        Config::set('payment.default', 'wompi');
        Config::set('payment.display_currency', 'USD');
        Config::set('payment.processing_currency', 'COP');
        Config::set('payment.usd_cop_rate', 4000);
        Config::set('payment.redirect_url', 'http://localhost:5173/checkout/result');
        Config::set('payment.drivers.wompi.environment', 'sandbox');
        Config::set('payment.drivers.wompi.public_key', 'pub_test_key');
        Config::set('payment.drivers.wompi.private_key', 'prv_test_key');
        Config::set('payment.drivers.wompi.integrity_secret', 'test_integrity_key');
        Config::set('payment.drivers.wompi.events_secret', 'test_events_key');
        Config::set('payment.drivers.wompi.checkout_url', 'https://checkout.wompi.co/p/');
        Config::set('payment.drivers.wompi.widget_url', 'https://checkout.wompi.co/widget.js');

        app(\App\Classes\PaymentService\PaymentManager::class)->forgetDrivers();
    }

    public function test_user_can_list_subscription_plans(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['subscription-plans:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::PLANS_ENDPOINT);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Subscription plans retrieved successfully.');
        $response->assertJsonPath('data.display_currency', 'USD');
        $response->assertJsonPath('data.processing_currency', 'COP');
        $response->assertJsonPath('data.exchange_rate', 4000);
        $response->assertJsonPath('data.plans.0.id', 'starter');
        $response->assertJsonPath('data.plans.0.purchasable', true);

        $plans = collect($response->json('data.plans'))->keyBy('id');

        $this->assertTrue($plans->has('starter_annual'));
        $this->assertSame(80, $plans->get('starter_annual')['price_usd']);
        $this->assertSame('annual', $plans->get('starter_annual')['interval']);
        $this->assertTrue($plans->get('starter_annual')['purchasable']);
    }

    public function test_user_can_create_wompi_checkout_for_starter_plan(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::CHECKOUT_ENDPOINT, ['plan' => 'starter']);

        $response->assertStatus(201);
        $response->assertJsonPath('message', 'Wompi checkout created successfully.');
        $response->assertJsonPath('data.payment_order.user_id', $user->id);
        $response->assertJsonPath('data.payment_order.plan', 'starter');
        $response->assertJsonPath('data.payment_order.amounts.display_amount_usd', 8);
        $response->assertJsonPath('data.payment_order.amounts.exchange_rate', 4000);
        $response->assertJsonPath('data.payment_order.amounts.amount_cop', 32000);
        $response->assertJsonPath('data.payment_order.amounts.amount_in_cents', 3200000);
        $response->assertJsonPath('data.payment_order.amounts.currency', 'COP');
        $response->assertJsonPath('data.payment_order.status', 'pending');
        $response->assertJsonPath('data.checkout.public_key', 'pub_test_key');
        $response->assertJsonPath('data.checkout.widget_url', 'https://checkout.wompi.co/widget.js');
        $this->assertStringStartsWith('https://checkout.wompi.co/p/?', $response->json('data.checkout.checkout_url'));
        $this->assertSame($response->json('data.payment_order.reference'), $response->json('data.checkout.reference'));

        $this->assertDatabaseHas('payment_orders', [
            'user_id' => $user->id,
            'plan' => 'starter',
            'provider' => 'wompi',
            'status' => 'pending',
            'amount_in_cents' => 3200000,
            'currency' => 'COP',
        ]);
    }

    public function test_user_can_create_wompi_checkout_for_starter_annual_plan(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::CHECKOUT_ENDPOINT, ['plan' => 'starter_annual']);

        $response->assertStatus(201);
        $response->assertJsonPath('message', 'Wompi checkout created successfully.');
        $response->assertJsonPath('data.payment_order.user_id', $user->id);
        $response->assertJsonPath('data.payment_order.plan', 'starter_annual');
        $response->assertJsonPath('data.payment_order.amounts.display_amount_usd', 80);
        $response->assertJsonPath('data.payment_order.amounts.exchange_rate', 4000);
        $response->assertJsonPath('data.payment_order.amounts.amount_cop', 320000);
        $response->assertJsonPath('data.payment_order.amounts.amount_in_cents', 32000000);
        $response->assertJsonPath('data.payment_order.amounts.currency', 'COP');
        $response->assertJsonPath('data.payment_order.status', 'pending');
        $this->assertStringStartsWith('https://checkout.wompi.co/p/?', $response->json('data.checkout.checkout_url'));

        $this->assertDatabaseHas('payment_orders', [
            'user_id' => $user->id,
            'plan' => 'starter_annual',
            'provider' => 'wompi',
            'status' => 'pending',
            'amount_in_cents' => 32000000,
            'currency' => 'COP',
        ]);
    }

    public function test_user_without_payments_create_ability_can_not_create_checkout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::CHECKOUT_ENDPOINT, ['plan' => 'starter']);

        $response->assertStatus(403);
    }

    public function test_plan_without_price_can_not_be_checked_out(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::CHECKOUT_ENDPOINT, ['plan' => 'pro']);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Selected plan is not available for checkout.');
    }

    public function test_user_can_read_own_payment_order(): void
    {
        $user = User::factory()->create();
        $paymentOrder = $this->createPendingPaymentOrder($user);
        $token = $user->createToken('test-token', ['payments:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/payments/{$paymentOrder->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Payment order retrieved successfully.');
        $response->assertJsonPath('data.id', $paymentOrder->id);
        $response->assertJsonPath('data.user_id', $user->id);
    }

    public function test_user_can_not_read_another_users_payment_order(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $paymentOrder = $this->createPendingPaymentOrder($owner);
        $token = $viewer->createToken('test-token', ['payments:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/payments/{$paymentOrder->id}");

        $response->assertStatus(403);
    }

    public function test_admin_can_read_another_users_payment_order(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $paymentOrder = $this->createPendingPaymentOrder($owner);
        $token = $admin->createToken('test-token', ['payments:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/payments/{$paymentOrder->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $paymentOrder->id);
    }

    public function test_valid_wompi_approved_event_approves_order_and_activates_subscription(): void
    {
        $user = User::factory()->create();
        $paymentOrder = $this->createPendingPaymentOrder($user);
        $payload = $this->wompiPayload($paymentOrder);

        $response = $this->postJson(self::WOMPI_EVENTS_ENDPOINT, $payload, [
            'X-Event-Checksum' => $this->eventChecksum($payload),
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Wompi event processed successfully.');

        $paymentOrder->refresh();
        $this->assertSame(PaymentOrderStatus::Approved, $paymentOrder->status);
        $this->assertSame('APPROVED', $paymentOrder->wompi_status);
        $this->assertSame('trx_'.$paymentOrder->reference, $paymentOrder->provider_transaction_id);
        $this->assertNotNull($paymentOrder->paid_at);
        $this->assertNotNull($paymentOrder->subscription_id);

        $this->assertDatabaseHas('subscriptions', [
            'id' => $paymentOrder->subscription_id,
            'user_id' => $user->id,
            'plan' => 'starter',
            'active' => true,
        ]);

        $this->assertDatabaseHas('subscription_limits', [
            'subscription_id' => $paymentOrder->subscription_id,
            'user_id' => $user->id,
            'credits_remaining' => 1000,
            'profiles_remaining' => 1,
        ]);

        $this->assertDatabaseHas('payment_events', [
            'provider' => 'wompi',
            'provider_event_id' => 'evt_'.$paymentOrder->reference,
            'payment_order_id' => $paymentOrder->id,
            'is_valid_signature' => true,
        ]);
    }

    public function test_valid_wompi_approved_event_activates_annual_subscription(): void
    {
        $user = User::factory()->create();
        $paymentOrder = $this->createPendingPaymentOrder($user, SubscriptionPlan::StarterAnnual, 80);
        $payload = $this->wompiPayload($paymentOrder);

        $response = $this->postJson(self::WOMPI_EVENTS_ENDPOINT, $payload, [
            'X-Event-Checksum' => $this->eventChecksum($payload),
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Wompi event processed successfully.');

        $paymentOrder->refresh();
        $subscription = $paymentOrder->subscription()->firstOrFail();

        $this->assertSame(PaymentOrderStatus::Approved, $paymentOrder->status);
        $this->assertSame(SubscriptionPlan::StarterAnnual, $subscription->plan);
        $this->assertTrue($subscription->renews_at->isSameDay($subscription->started_at->copy()->addYear()));

        $this->assertDatabaseHas('subscription_limits', [
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'credits_remaining' => 12000,
            'profiles_remaining' => 12,
        ]);
    }

    public function test_duplicate_wompi_event_is_idempotent(): void
    {
        $user = User::factory()->create();
        $paymentOrder = $this->createPendingPaymentOrder($user);
        $payload = $this->wompiPayload($paymentOrder);
        $checksum = $this->eventChecksum($payload);

        $this->postJson(self::WOMPI_EVENTS_ENDPOINT, $payload, ['X-Event-Checksum' => $checksum])
            ->assertStatus(200);

        $this->postJson(self::WOMPI_EVENTS_ENDPOINT, $payload, ['X-Event-Checksum' => $checksum])
            ->assertStatus(200)
            ->assertJsonPath('message', 'Wompi event already processed.');

        $this->assertSame(1, Subscription::where('user_id', $user->id)->count());
        $this->assertSame(1, PaymentEvent::where('provider_event_id', 'evt_'.$paymentOrder->reference)->count());
    }

    public function test_invalid_wompi_checksum_does_not_approve_order(): void
    {
        $user = User::factory()->create();
        $paymentOrder = $this->createPendingPaymentOrder($user);

        $response = $this->postJson(self::WOMPI_EVENTS_ENDPOINT, $this->wompiPayload($paymentOrder), [
            'X-Event-Checksum' => 'invalid',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Wompi event ignored.');

        $paymentOrder->refresh();
        $this->assertSame(PaymentOrderStatus::Pending, $paymentOrder->status);
        $this->assertNull($paymentOrder->subscription_id);
        $this->assertSame(0, Subscription::where('user_id', $user->id)->count());
    }

    private function createPendingPaymentOrder(
        User $user,
        SubscriptionPlan $plan = SubscriptionPlan::Starter,
        float $displayAmountUsd = 8,
    ): PaymentOrder {
        $amountInCents = (int) round($displayAmountUsd * 4000 * 100);

        return PaymentOrder::create([
            'user_id' => $user->id,
            'provider' => PaymentProvider::Wompi,
            'reference' => 'VOI-'.$user->id.'-'.$this->faker->unique()->bothify('????####'),
            'plan' => $plan,
            'display_amount_usd' => $displayAmountUsd,
            'display_currency' => 'USD',
            'exchange_rate' => 4000,
            'amount_cop' => round($amountInCents / 100, 2),
            'amount_in_cents' => $amountInCents,
            'currency' => 'COP',
            'status' => PaymentOrderStatus::Pending,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function wompiPayload(PaymentOrder $paymentOrder): array
    {
        return [
            'id' => 'evt_'.$paymentOrder->reference,
            'event' => 'transaction.updated',
            'data' => [
                'transaction' => [
                    'id' => 'trx_'.$paymentOrder->reference,
                    'amount_in_cents' => $paymentOrder->amount_in_cents,
                    'reference' => $paymentOrder->reference,
                    'currency' => $paymentOrder->currency->value,
                    'status' => 'APPROVED',
                ],
            ],
            'environment' => 'test',
            'signature' => [
                'properties' => [
                    'transaction.id',
                    'transaction.status',
                    'transaction.amount_in_cents',
                ],
                'checksum' => 'checksum-in-header',
            ],
            'timestamp' => 1530291411,
            'sent_at' => '2018-07-20T16:45:05.000Z',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function eventChecksum(array $payload): string
    {
        $transaction = $payload['data']['transaction'];

        return hash(
            'sha256',
            $transaction['id'].$transaction['status'].$transaction['amount_in_cents'].$payload['timestamp'].'test_events_key',
        );
    }
}
