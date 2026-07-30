<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\PaymentEvent;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class PaymentControllerTest extends TestAPI
{
    private const CHECKOUT_ENDPOINT = '/api/payments/wompi/checkout';

    private const TRIAL_ENDPOINT = '/api/subscription/trial';

    private const TRIAL_PAYMENT_SOURCE_SETUP_ENDPOINT = '/api/subscription/trial/payment-source-setup';

    private const SUBSCRIPTION_PAYMENT_SOURCE_SETUP_ENDPOINT = '/api/subscription/payment-source-setup';

    private const SUBSCRIPTION_PAYMENT_SOURCE_ENDPOINT = '/api/subscription/payment-source';

    private const PLANS_ENDPOINT = '/api/subscription/plans';

    private const WOMPI_EVENTS_ENDPOINT = '/api/payments/wompi/events';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.default', 'wompi');
        Config::set('payment.display_currency', 'USD');
        Config::set('payment.processing_currency', 'COP');
        Config::set('payment.usd_cop_rate', 4000);
        Config::set('payment.redirect_url', 'http://localhost:3000/dashboard/settings/billing/payment-result');
        Config::set('payment.drivers.wompi.environment', 'sandbox');
        Config::set('payment.drivers.wompi.public_key', 'pub_test_key');
        Config::set('payment.drivers.wompi.private_key', 'prv_test_key');
        Config::set('payment.drivers.wompi.integrity_secret', 'test_integrity_key');
        Config::set('payment.drivers.wompi.events_secret', 'test_events_key');
        Config::set('payment.drivers.wompi.checkout_url', 'https://checkout.wompi.co/p/');
        Config::set('payment.drivers.wompi.widget_url', 'https://checkout.wompi.co/widget.js');
        Config::set('payment.drivers.wompi.api_url', 'https://sandbox.wompi.co/v1');
        Config::set('subscriptions.trial.enabled', true);
        Config::set('subscriptions.trial.days', 7);
        Config::set('subscriptions.trial.setup_amount_usd', 0);
        Config::set('subscriptions.trial.requires_payment_source', true);

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
        $response->assertJsonPath('data.trial.enabled', true);
        $response->assertJsonPath('data.trial.available', true);
        $response->assertJsonPath('data.trial.days', 7);
        $response->assertJsonPath('data.plans.0.id', 'starter');
        $response->assertJsonPath('data.plans.0.purchasable', true);

        $plans = collect($response->json('data.plans'))->keyBy('id');

        $this->assertFalse($plans->has('admin'));
        $this->assertFalse($plans->has('pro'));
        $this->assertFalse($plans->has('business'));
        $this->assertTrue($plans->has('starter_annual'));
        $this->assertSame(12.99, $plans->get('starter')['price_usd']);
        $this->assertSame(129, $plans->get('starter_annual')['price_usd']);
        $this->assertSame('annual', $plans->get('starter_annual')['interval']);
        $this->assertSame(1, $plans->get('starter_annual')['limits']['profiles']);
        $this->assertSame(1000, $plans->get('starter_annual')['credits']['total']);
        $this->assertTrue($plans->get('starter_annual')['purchasable']);
    }

    public function test_user_can_get_trial_payment_source_setup(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/merchants/pub_test_key' => Http::response([
                'data' => [
                    'presigned_acceptance' => [
                        'acceptance_token' => 'acceptance-token',
                        'permalink' => 'https://wompi.test/terms.pdf',
                    ],
                    'presigned_personal_data_auth' => [
                        'acceptance_token' => 'personal-auth-token',
                        'permalink' => 'https://wompi.test/privacy.pdf',
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::TRIAL_PAYMENT_SOURCE_SETUP_ENDPOINT);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Subscription trial payment source setup retrieved successfully.');
        $response->assertJsonPath('data.source', 'wompi');
        $response->assertJsonPath('data.public_key', 'pub_test_key');
        $response->assertJsonPath('data.api_url', 'https://sandbox.wompi.co/v1');
        $response->assertJsonPath('data.acceptance.acceptance_token', 'acceptance-token');
        $response->assertJsonPath('data.personal_data_auth.acceptance_token', 'personal-auth-token');
    }

    public function test_user_can_get_subscription_payment_source_setup(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/merchants/pub_test_key' => Http::response([
                'data' => [
                    'presigned_acceptance' => [
                        'acceptance_token' => 'acceptance-token',
                        'permalink' => 'https://wompi.test/terms.pdf',
                    ],
                    'presigned_personal_data_auth' => [
                        'acceptance_token' => 'personal-auth-token',
                        'permalink' => 'https://wompi.test/privacy.pdf',
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::SUBSCRIPTION_PAYMENT_SOURCE_SETUP_ENDPOINT);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Subscription payment source setup retrieved successfully.');
        $response->assertJsonPath('data.source', 'wompi');
        $response->assertJsonPath('data.public_key', 'pub_test_key');
        $response->assertJsonPath('data.acceptance.acceptance_token', 'acceptance-token');
        $response->assertJsonPath('data.personal_data_auth.acceptance_token', 'personal-auth-token');
    }

    public function test_user_can_start_trial_with_active_payment_source(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/payment_sources' => Http::response([
                'data' => [
                    'id' => 3891,
                    'type' => 'CARD',
                    'status' => 'AVAILABLE',
                    'public_data' => [
                        'brand' => 'VISA',
                        'last_four' => '4242',
                        'exp_month' => '06',
                        'exp_year' => '29',
                    ],
                ],
            ], 201),
        ]);

        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::TRIAL_ENDPOINT, [
                'plan' => 'starter',
                'terms_accepted' => true,
                'payment_source' => [
                    'type' => 'CARD',
                    'token' => 'tok_test_4242',
                    'acceptance_token' => 'acceptance-token',
                    'accept_personal_auth' => 'personal-auth-token',
                    'session_id' => 'session-1',
                    'customer_data' => [
                        'device_id' => 'device-1',
                        'full_name' => $user->name,
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('message', 'Subscription trial started successfully.');
        $response->assertJsonPath('data.subscription.plan', 'starter');
        $response->assertJsonPath('data.subscription.status', 'trialing');
        $response->assertJsonPath('data.payment_source.provider_source_id', '3891');
        $response->assertJsonPath('data.payment_source.status', 'active');
        $response->assertJsonPath('data.payment_order.user_id', $user->id);
        $response->assertJsonPath('data.payment_order.plan', 'starter');
        $response->assertJsonPath('data.payment_order.amounts.display_amount_usd', 0);
        $response->assertJsonPath('data.payment_order.amounts.amount_cop', 0);
        $response->assertJsonPath('data.payment_order.amounts.amount_in_cents', 0);
        $response->assertJsonPath('data.payment_order.status', 'approved');
        $response->assertJsonPath('data.payment_order.recurring', true);
        $response->assertJsonPath('data.payment_order.billing_reason', 'trial_setup');
        $response->assertJsonPath('data.payment_order.customer_terms.version', '2026-07-29');
        $response->assertJsonPath('data.payment_order.customer_terms.accepted_plan_price_usd', 12.99);
        $this->assertNotNull($response->json('data.payment_order.customer_terms.accepted_at'));

        $user->refresh();
        $this->assertNotNull($user->free_trial_used_at);
        $this->assertDatabaseHas('payment_orders', [
            'user_id' => $user->id,
            'plan' => 'starter',
            'provider' => 'wompi',
            'status' => 'approved',
            'recurring' => true,
            'billing_reason' => 'trial_setup',
            'customer_terms_version' => '2026-07-29',
            'accepted_plan_price_usd' => 12.99,
            'amount_in_cents' => 0,
            'currency' => 'COP',
        ]);
        $this->assertDatabaseHas('payment_sources', [
            'user_id' => $user->id,
            'provider' => 'wompi',
            'provider_source_id' => '3891',
            'type' => 'CARD',
            'status' => 'active',
            'reusable' => true,
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'plan' => 'starter',
            'status' => 'trialing',
            'active' => true,
            'billing_mode' => 'recurring',
        ]);

        Http::assertSent(function ($request) use ($user): bool {
            return $request->url() === 'https://sandbox.wompi.co/v1/payment_sources'
                && ($request->header('Authorization')[0] ?? null) === 'Bearer prv_test_key'
                && $request['type'] === 'CARD'
                && $request['token'] === 'tok_test_4242'
                && $request['customer_email'] === $user->email
                && $request['acceptance_token'] === 'acceptance-token'
                && $request['accept_personal_auth'] === 'personal-auth-token'
                && $request['session_id'] === 'session-1'
                && $request['customer_data']['device_id'] === 'device-1';
        });
    }

    public function test_user_with_existing_subscription_can_not_start_trial(): void
    {
        $user = User::factory()->create();
        Subscription::query()->create([
            'user_id' => $user->id,
            'plan' => SubscriptionPlan::Starter,
            'billing_mode' => 'recurring',
            'started_at' => now()->subDay(),
            'renews_at' => now()->addMonth(),
            'status' => SubscriptionStatus::First,
            'active' => true,
            'cancel_at_period_end' => false,
            'next_billing_at' => now()->addMonth(),
        ]);
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::TRIAL_ENDPOINT, [
                'plan' => 'starter',
                'terms_accepted' => true,
                'payment_source' => [
                    'type' => 'CARD',
                    'token' => 'tok_test_4242',
                    'acceptance_token' => 'acceptance-token',
                    'accept_personal_auth' => 'personal-auth-token',
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Free trial is only available before the first subscription.');
    }

    public function test_user_can_start_paid_subscription_with_payment_source(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/payment_sources' => Http::response([
                'data' => [
                    'id' => 3891,
                    'type' => 'CARD',
                    'status' => 'AVAILABLE',
                    'public_data' => [
                        'brand' => 'VISA',
                        'last_four' => '4242',
                    ],
                ],
            ], 201),
            'https://sandbox.wompi.co/v1/transactions' => Http::response([
                'data' => [
                    'id' => 'trx_initial_1',
                    'status' => 'APPROVED',
                    'amount_in_cents' => 5196000,
                    'currency' => 'COP',
                ],
            ], 201),
        ]);

        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::SUBSCRIPTION_PAYMENT_SOURCE_ENDPOINT, [
                'plan' => 'starter',
                'terms_accepted' => true,
                'payment_source' => [
                    'type' => 'CARD',
                    'token' => 'tok_test_4242',
                    'acceptance_token' => 'acceptance-token',
                    'accept_personal_auth' => 'personal-auth-token',
                    'session_id' => 'session-1',
                    'customer_data' => [
                        'device_id' => 'device-1',
                        'full_name' => $user->name,
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('message', 'Subscription payment source checkout processed successfully.');
        $response->assertJsonPath('data.subscription.plan', 'starter');
        $response->assertJsonPath('data.subscription.status', 'first');
        $response->assertJsonPath('data.payment_source.provider_source_id', '3891');
        $response->assertJsonPath('data.payment_source.status', 'active');
        $response->assertJsonPath('data.payment_order.user_id', $user->id);
        $response->assertJsonPath('data.payment_order.plan', 'starter');
        $response->assertJsonPath('data.payment_order.amounts.display_amount_usd', 12.99);
        $response->assertJsonPath('data.payment_order.amounts.amount_in_cents', 5196000);
        $response->assertJsonPath('data.payment_order.status', 'approved');
        $response->assertJsonPath('data.payment_order.recurring', true);
        $response->assertJsonPath('data.payment_order.billing_reason', 'subscription_initial');

        $this->assertDatabaseHas('payment_orders', [
            'user_id' => $user->id,
            'plan' => 'starter',
            'provider' => 'wompi',
            'status' => 'approved',
            'recurring' => true,
            'billing_reason' => 'subscription_initial',
            'amount_in_cents' => 5196000,
            'currency' => 'COP',
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'plan' => 'starter',
            'status' => 'first',
            'active' => true,
            'billing_mode' => 'recurring',
        ]);

        Http::assertSent(function ($request) use ($user): bool {
            return $request->url() === 'https://sandbox.wompi.co/v1/transactions'
                && ($request->header('Authorization')[0] ?? null) === 'Bearer prv_test_key'
                && $request['amount_in_cents'] === 5196000
                && $request['currency'] === 'COP'
                && $request['customer_email'] === $user->email
                && $request['payment_source_id'] === 3891
                && $request['recurrent'] === true
                && str_starts_with((string) $request['reference'], 'VOI-SUB-'.$user->id.'-');
        });
    }

    public function test_paid_subscription_started_with_payment_source_can_be_renewed_by_job(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/payment_sources' => Http::response([
                'data' => [
                    'id' => 3891,
                    'type' => 'CARD',
                    'status' => 'AVAILABLE',
                    'public_data' => [
                        'brand' => 'VISA',
                        'last_four' => '4242',
                    ],
                ],
            ], 201),
            'https://sandbox.wompi.co/v1/transactions' => Http::sequence()
                ->push([
                    'data' => [
                        'id' => 'trx_initial_1',
                        'status' => 'APPROVED',
                        'amount_in_cents' => 5196000,
                        'currency' => 'COP',
                    ],
                ], 201)
                ->push([
                    'data' => [
                        'id' => 'trx_renewal_1',
                        'status' => 'APPROVED',
                        'amount_in_cents' => 5196000,
                        'currency' => 'COP',
                    ],
                ], 201),
        ]);

        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::SUBSCRIPTION_PAYMENT_SOURCE_ENDPOINT, [
                'plan' => 'starter',
                'terms_accepted' => true,
                'payment_source' => [
                    'type' => 'CARD',
                    'token' => 'tok_test_4242',
                    'acceptance_token' => 'acceptance-token',
                    'accept_personal_auth' => 'personal-auth-token',
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.payment_order.status', 'approved');

        $subscription = Subscription::query()
            ->where('user_id', $user->id)
            ->where('active', true)
            ->firstOrFail();
        $subscription->forceFill([
            'renews_at' => now()->subMinute(),
            'next_billing_at' => now()->subMinute(),
        ])->save();

        $this->artisan('subscriptions:bill-recurring')
            ->expectsOutput('Recurring billing processed: 1. Approved: 1. Pending: 0. Failed: 0. Skipped: 0.')
            ->assertSuccessful();

        $this->assertDatabaseHas('payment_orders', [
            'user_id' => $user->id,
            'billing_reason' => 'subscription_initial',
            'status' => 'approved',
            'amount_in_cents' => 5196000,
        ]);
        $this->assertDatabaseHas('payment_orders', [
            'user_id' => $user->id,
            'billing_reason' => 'subscription_renewal',
            'status' => 'approved',
            'amount_in_cents' => 5196000,
        ]);
        $this->assertSame(2, PaymentOrder::query()->where('user_id', $user->id)->where('status', 'approved')->count());

        $activeSubscription = Subscription::query()
            ->where('user_id', $user->id)
            ->where('active', true)
            ->firstOrFail();

        $this->assertNotSame($subscription->id, $activeSubscription->id);
        $this->assertSame(SubscriptionStatus::Renewed, $activeSubscription->status);
        $this->assertNotNull($activeSubscription->payment_source_id);
    }

    public function test_declined_initial_payment_source_charge_does_not_activate_subscription(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/payment_sources' => Http::response([
                'data' => [
                    'id' => 3891,
                    'type' => 'CARD',
                    'status' => 'AVAILABLE',
                ],
            ], 201),
            'https://sandbox.wompi.co/v1/transactions' => Http::response([
                'data' => [
                    'id' => 'trx_initial_declined',
                    'status' => 'DECLINED',
                    'amount_in_cents' => 5196000,
                    'currency' => 'COP',
                ],
            ], 201),
        ]);

        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::SUBSCRIPTION_PAYMENT_SOURCE_ENDPOINT, [
                'plan' => 'starter',
                'terms_accepted' => true,
                'payment_source' => [
                    'type' => 'CARD',
                    'token' => 'tok_test_declined',
                    'acceptance_token' => 'acceptance-token',
                    'accept_personal_auth' => 'personal-auth-token',
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.subscription', null);
        $response->assertJsonPath('data.payment_order.status', 'declined');
        $response->assertJsonPath('data.payment_order.billing_reason', 'subscription_initial');

        $this->assertSame(0, Subscription::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('payment_orders', [
            'user_id' => $user->id,
            'status' => 'declined',
            'billing_reason' => 'subscription_initial',
        ]);
    }

    public function test_pending_payment_source_does_not_start_trial(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/payment_sources' => Http::response([
                'data' => [
                    'id' => 3892,
                    'type' => 'CARD',
                    'status' => 'PENDING',
                ],
            ], 201),
        ]);

        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::TRIAL_ENDPOINT, [
                'plan' => 'starter',
                'terms_accepted' => true,
                'payment_source' => [
                    'type' => 'CARD',
                    'token' => 'tok_test_pending',
                    'acceptance_token' => 'acceptance-token',
                    'accept_personal_auth' => 'personal-auth-token',
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Wompi did not confirm an active reusable payment source.');

        $this->assertDatabaseCount('payment_sources', 0);
        $this->assertDatabaseCount('payment_orders', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_user_can_create_wompi_checkout_for_starter_plan(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::CHECKOUT_ENDPOINT, ['plan' => 'starter', 'terms_accepted' => true]);

        $response->assertStatus(201);
        $response->assertJsonPath('message', 'Wompi checkout created successfully.');
        $response->assertJsonPath('data.payment_order.user_id', $user->id);
        $response->assertJsonPath('data.payment_order.plan', 'starter');
        $response->assertJsonPath('data.payment_order.amounts.display_amount_usd', 12.99);
        $response->assertJsonPath('data.payment_order.amounts.exchange_rate', 4000);
        $response->assertJsonPath('data.payment_order.amounts.amount_cop', 51960);
        $response->assertJsonPath('data.payment_order.amounts.amount_in_cents', 5196000);
        $response->assertJsonPath('data.payment_order.amounts.currency', 'COP');
        $response->assertJsonPath('data.payment_order.status', 'pending');
        $response->assertJsonPath('data.payment_order.recurring', true);
        $response->assertJsonPath('data.payment_order.billing_reason', 'subscription_initial');
        $response->assertJsonPath('data.payment_order.customer_terms.version', '2026-07-29');
        $response->assertJsonPath('data.payment_order.customer_terms.accepted_plan_price_usd', 12.99);
        $response->assertJsonPath('data.checkout.public_key', 'pub_test_key');
        $response->assertJsonPath('data.checkout.widget_url', 'https://checkout.wompi.co/widget.js');
        $this->assertStringStartsWith('https://checkout.wompi.co/p/?', $response->json('data.checkout.checkout_url'));
        $this->assertSame($response->json('data.payment_order.reference'), $response->json('data.checkout.reference'));
        $this->assertIsString($response->json('data.checkout.redirect_url'));
        $this->assertStringStartsWith(
            'http://localhost:3000/dashboard/settings/billing/payment-result?',
            $response->json('data.checkout.redirect_url')
        );
        $this->assertStringContainsString(
            'payment_order_id='.$response->json('data.payment_order.id'),
            $response->json('data.checkout.redirect_url')
        );

        $this->assertDatabaseHas('payment_orders', [
            'user_id' => $user->id,
            'plan' => 'starter',
            'provider' => 'wompi',
            'status' => 'pending',
            'recurring' => true,
            'billing_reason' => 'subscription_initial',
            'customer_terms_version' => '2026-07-29',
            'accepted_plan_price_usd' => 12.99,
            'amount_in_cents' => 5196000,
            'currency' => 'COP',
        ]);
    }

    public function test_user_can_create_wompi_checkout_for_starter_annual_plan(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::CHECKOUT_ENDPOINT, ['plan' => 'starter_annual', 'terms_accepted' => true]);

        $response->assertStatus(201);
        $response->assertJsonPath('message', 'Wompi checkout created successfully.');
        $response->assertJsonPath('data.payment_order.user_id', $user->id);
        $response->assertJsonPath('data.payment_order.plan', 'starter_annual');
        $response->assertJsonPath('data.payment_order.amounts.display_amount_usd', 129);
        $response->assertJsonPath('data.payment_order.amounts.exchange_rate', 4000);
        $response->assertJsonPath('data.payment_order.amounts.amount_cop', 516000);
        $response->assertJsonPath('data.payment_order.amounts.amount_in_cents', 51600000);
        $response->assertJsonPath('data.payment_order.amounts.currency', 'COP');
        $response->assertJsonPath('data.payment_order.status', 'pending');
        $response->assertJsonPath('data.payment_order.recurring', true);
        $response->assertJsonPath('data.payment_order.customer_terms.accepted_plan_price_usd', 129);
        $this->assertStringStartsWith('https://checkout.wompi.co/p/?', $response->json('data.checkout.checkout_url'));

        $this->assertDatabaseHas('payment_orders', [
            'user_id' => $user->id,
            'plan' => 'starter_annual',
            'provider' => 'wompi',
            'status' => 'pending',
            'recurring' => true,
            'customer_terms_version' => '2026-07-29',
            'accepted_plan_price_usd' => 129,
            'amount_in_cents' => 51600000,
            'currency' => 'COP',
        ]);
    }

    public function test_user_without_payments_create_ability_can_not_create_checkout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::CHECKOUT_ENDPOINT, ['plan' => 'starter', 'terms_accepted' => true]);

        $response->assertStatus(403);
    }

    public function test_customer_terms_must_be_accepted_before_checkout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::CHECKOUT_ENDPOINT, ['plan' => 'starter', 'terms_accepted' => false]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('terms_accepted');
        $this->assertDatabaseCount('payment_orders', 0);
    }

    public function test_plan_without_price_can_not_be_checked_out(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::CHECKOUT_ENDPOINT, ['plan' => 'pro', 'terms_accepted' => true]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Selected plan is not available for checkout.');
    }

    public function test_admin_plan_can_not_be_checked_out(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::CHECKOUT_ENDPOINT, ['plan' => 'admin', 'terms_accepted' => true]);

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
        $this->assertNotNull($paymentOrder->payment_source_id);

        $this->assertDatabaseHas('subscriptions', [
            'id' => $paymentOrder->subscription_id,
            'user_id' => $user->id,
            'plan' => 'starter',
            'active' => true,
            'billing_mode' => 'recurring',
            'source_payment_order_id' => $paymentOrder->id,
        ]);

        $this->assertDatabaseHas('payment_sources', [
            'id' => $paymentOrder->payment_source_id,
            'user_id' => $user->id,
            'provider' => 'wompi',
            'provider_source_id' => 'ps_'.$paymentOrder->reference,
            'status' => 'active',
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
        $paymentOrder = $this->createPendingPaymentOrder($user, SubscriptionPlan::StarterAnnual, 129);
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
        $this->assertTrue($subscription->next_billing_at->isSameDay($subscription->started_at->copy()->addYear()));

        $limit = $subscription->limit()->firstOrFail();

        $this->assertTrue($limit->period_renews_at->isSameDay($subscription->started_at->copy()->addMonth()));

        $this->assertDatabaseHas('subscription_limits', [
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'credits_remaining' => 1000,
            'profiles_remaining' => 1,
        ]);
    }

    public function test_valid_wompi_approved_event_starts_trial_subscription(): void
    {
        $user = User::factory()->create();
        $paymentOrder = $this->createPendingPaymentOrder($user, SubscriptionPlan::Starter, 0, 'trial_setup');
        $payload = $this->wompiPayload($paymentOrder);

        $response = $this->postJson(self::WOMPI_EVENTS_ENDPOINT, $payload, [
            'X-Event-Checksum' => $this->eventChecksum($payload),
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Wompi event processed successfully.');

        $paymentOrder->refresh();
        $subscription = $paymentOrder->subscription()->firstOrFail();
        $user->refresh();

        $this->assertSame(PaymentOrderStatus::Approved, $paymentOrder->status);
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertTrue($subscription->active);
        $this->assertSame('recurring', $subscription->billing_mode);
        $this->assertTrue($subscription->trial_ends_at->isSameDay($subscription->started_at->copy()->addDays(7)));
        $this->assertTrue($subscription->next_billing_at->isSameDay($subscription->trial_ends_at));
        $this->assertNotNull($subscription->payment_source_id);
        $this->assertNotNull($user->free_trial_used_at);

        $this->assertDatabaseHas('subscription_limits', [
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'credits_remaining' => 100,
            'profiles_remaining' => 1,
        ]);
    }

    public function test_trial_approved_event_without_payment_source_does_not_start_trial(): void
    {
        $user = User::factory()->create();
        $paymentOrder = $this->createPendingPaymentOrder($user, SubscriptionPlan::Starter, 0, 'trial_setup');
        $payload = $this->wompiPayload($paymentOrder, includePaymentSource: false);

        $response = $this->postJson(self::WOMPI_EVENTS_ENDPOINT, $payload, [
            'X-Event-Checksum' => $this->eventChecksum($payload),
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Wompi event processed successfully.');

        $paymentOrder->refresh();
        $user->refresh();

        $this->assertSame(PaymentOrderStatus::Error, $paymentOrder->status);
        $this->assertNull($paymentOrder->subscription_id);
        $this->assertNull($user->free_trial_used_at);
        $this->assertSame(0, Subscription::where('user_id', $user->id)->count());
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
        string $billingReason = 'subscription_initial',
    ): PaymentOrder {
        $amountInCents = (int) round($displayAmountUsd * 4000 * 100);

        return PaymentOrder::create([
            'user_id' => $user->id,
            'provider' => PaymentProvider::Wompi,
            'reference' => 'VOI-'.$user->id.'-'.$this->faker->unique()->bothify('????####'),
            'plan' => $plan,
            'recurring' => true,
            'billing_reason' => $billingReason,
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
    private function wompiPayload(PaymentOrder $paymentOrder, bool $includePaymentSource = true): array
    {
        $transaction = [
            'id' => 'trx_'.$paymentOrder->reference,
            'amount_in_cents' => $paymentOrder->amount_in_cents,
            'reference' => $paymentOrder->reference,
            'currency' => $paymentOrder->currency->value,
            'status' => 'APPROVED',
            'payment_method_type' => 'CARD',
        ];

        if ($includePaymentSource) {
            $transaction['payment_source_id'] = 'ps_'.$paymentOrder->reference;
        }

        return [
            'id' => 'evt_'.$paymentOrder->reference,
            'event' => 'transaction.updated',
            'data' => [
                'transaction' => $transaction,
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
