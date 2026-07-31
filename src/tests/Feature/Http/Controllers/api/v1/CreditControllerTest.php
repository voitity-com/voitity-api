<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Classes\PaymentService\PaymentManager;
use App\Enums\PaymentProvider;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\CreditLedgerEntry;
use App\Models\CreditWallet;
use App\Models\PaymentSource;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesSubscriptionScenarios;

class CreditControllerTest extends TestAPI
{
    use CreatesSubscriptionScenarios;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.default', 'wompi');
        Config::set('payment.usd_cop_rate', 4000);
        Config::set('payment.pending_charge_poll_attempts', 0);
        Config::set('payment.drivers.wompi.environment', 'sandbox');
        Config::set('payment.drivers.wompi.public_key', 'pub_test_key');
        Config::set('payment.drivers.wompi.private_key', 'prv_test_key');
        Config::set('payment.drivers.wompi.integrity_secret', 'test_integrity_key');
        Config::set('payment.drivers.wompi.events_secret', 'test_events_key');
        Config::set('payment.drivers.wompi.api_url', 'https://sandbox.wompi.co/v1');

        app(PaymentManager::class)->forgetDrivers();
    }

    public function test_user_can_read_credit_catalog_and_empty_wallet(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/credits/catalog')
            ->assertOk()
            ->assertJsonPath('data.price_per_1000_usd', 10)
            ->assertJsonPath('data.minimum_purchase_credits', 1000)
            ->assertJsonPath('data.packages.1.credits', 2000)
            ->assertJsonPath('data.rates.tts_characters', 0.025);

        $this->withToken($token)
            ->getJson('/api/credits/wallet')
            ->assertOk()
            ->assertJsonPath('data.available', 0)
            ->assertJsonPath('data.lifetime_purchased', 0);
    }

    public function test_paid_user_can_purchase_credits_with_reusable_payment_source(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/transactions' => Http::response([
                'data' => [
                    'id' => 'trx_credits_1',
                    'status' => 'APPROVED',
                    'amount_in_cents' => 4000000,
                    'currency' => 'COP',
                ],
            ], 201),
        ]);

        [$user, $token] = $this->paidUser();

        $response = $this->withToken($token)->postJson('/api/credits/purchases', [
            'credits' => 1000,
            'idempotency_key' => 'credits-purchase-1',
            'terms_accepted' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment_order.product_type', 'credit_pack')
            ->assertJsonPath('data.payment_order.credits', 1000)
            ->assertJsonPath('data.payment_order.amounts.display_amount_usd', 10)
            ->assertJsonPath('data.payment_order.amounts.amount_in_cents', 4000000)
            ->assertJsonPath('data.payment_order.status', 'approved')
            ->assertJsonPath('data.wallet.available', 1000)
            ->assertJsonPath('data.wallet.lifetime_purchased', 1000);

        $wallet = CreditWallet::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(1000000, $wallet->available_units);
        $this->assertSame(1, CreditLedgerEntry::where('type', 'purchase')->count());
        $this->assertDatabaseHas('payment_orders', [
            'user_id' => $user->id,
            'product_type' => 'credit_pack',
            'credit_units' => 1000000,
            'purchase_idempotency_key' => 'credits-purchase-1',
            'status' => 'approved',
            'recurring' => false,
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://sandbox.wompi.co/v1/transactions'
                && $request['amount_in_cents'] === 4000000
                && $request['payment_source_id'] === 3891
                && $request['recurrent'] === false;
        });
    }

    public function test_credit_purchase_request_is_idempotent(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/transactions' => Http::response([
                'data' => [
                    'id' => 'trx_credits_idempotent',
                    'status' => 'APPROVED',
                    'amount_in_cents' => 4000000,
                    'currency' => 'COP',
                ],
            ], 201),
        ]);

        [$user, $token] = $this->paidUser();
        $payload = [
            'credits' => 1000,
            'idempotency_key' => 'credits-purchase-idempotent',
            'terms_accepted' => true,
        ];

        $this->withToken($token)->postJson('/api/credits/purchases', $payload)->assertCreated();
        $this->withToken($token)->postJson('/api/credits/purchases', $payload)->assertCreated();

        $this->assertSame(1, $user->paymentOrders()->where('product_type', 'credit_pack')->count());
        $this->assertSame(1000000, $user->creditWallet()->firstOrFail()->available_units);
        $this->assertSame(1, CreditLedgerEntry::where('type', 'purchase')->count());
        Http::assertSentCount(1);
    }

    public function test_user_can_purchase_with_a_chargeable_secondary_method_without_changing_default(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/transactions' => Http::response([
                'data' => [
                    'id' => 'trx_credits_secondary',
                    'status' => 'APPROVED',
                    'amount_in_cents' => 4000000,
                    'currency' => 'COP',
                ],
            ], 201),
        ]);

        [$user, $token] = $this->paidUser();
        $default = PaymentSource::query()->where('user_id', $user->id)->firstOrFail();
        $secondary = PaymentSource::query()->create([
            'user_id' => $user->id,
            'provider' => PaymentProvider::Wompi,
            'provider_source_id' => 'secondary-source',
            'type' => 'CARD',
            'card_last_four' => '2222',
            'status' => 'active',
            'reusable' => true,
            'is_default' => false,
            'verified_at' => now(),
        ]);

        $this->withToken($token)->postJson('/api/credits/purchases', [
            'credits' => 1000,
            'idempotency_key' => 'credits-secondary-method',
            'payment_source_id' => $secondary->id,
            'terms_accepted' => true,
        ])->assertCreated()
            ->assertJsonPath('data.payment_order.status', 'approved');

        $subscription = $user->subscriptions()->where('active', true)->firstOrFail();
        $this->assertSame($secondary->id, $user->paymentOrders()->latest('id')->firstOrFail()->payment_source_id);
        $this->assertSame($default->id, $subscription->payment_source_id);
        $this->assertTrue($default->fresh()->is_default);
        $this->assertFalse($secondary->fresh()->is_default);

        Http::assertSent(fn ($request): bool => $request['payment_source_id'] === 'secondary-source');
    }

    public function test_credit_purchase_rejects_an_unchargeable_selected_method(): void
    {
        Http::fake();

        [$user, $token] = $this->paidUser();
        $rejected = PaymentSource::query()->create([
            'user_id' => $user->id,
            'provider' => PaymentProvider::Wompi,
            'provider_source_id' => 'rejected-secondary-source',
            'type' => 'CARD',
            'card_last_four' => '3333',
            'status' => 'active',
            'reusable' => true,
            'is_default' => false,
            'requires_attention' => true,
            'verified_at' => now(),
        ]);

        $this->withToken($token)->postJson('/api/credits/purchases', [
            'credits' => 1000,
            'idempotency_key' => 'credits-rejected-secondary',
            'payment_source_id' => $rejected->id,
            'terms_accepted' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'PAYMENT_METHOD_REQUIRED');

        $this->assertDatabaseMissing('payment_orders', [
            'purchase_idempotency_key' => 'credits-rejected-secondary',
        ]);
        Http::assertNothingSent();
    }

    public function test_credit_purchase_cannot_use_another_users_method(): void
    {
        Http::fake();

        [, $token] = $this->paidUser();
        $otherUser = User::factory()->create();
        $otherSource = PaymentSource::query()->create([
            'user_id' => $otherUser->id,
            'provider' => PaymentProvider::Wompi,
            'provider_source_id' => 'other-user-source',
            'type' => 'CARD',
            'status' => 'active',
            'reusable' => true,
            'verified_at' => now(),
        ]);

        $this->withToken($token)->postJson('/api/credits/purchases', [
            'credits' => 1000,
            'idempotency_key' => 'credits-other-user-method',
            'payment_source_id' => $otherSource->id,
            'terms_accepted' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'PAYMENT_METHOD_REQUIRED');

        $this->assertDatabaseMissing('payment_orders', [
            'purchase_idempotency_key' => 'credits-other-user-method',
        ]);
        Http::assertNothingSent();
    }

    public function test_declined_credit_purchase_marks_card_and_does_not_grant_credits(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/transactions' => Http::sequence()
                ->push([
                    'data' => [
                        'id' => 'trx_credits_declined',
                        'status' => 'DECLINED',
                        'amount_in_cents' => 4000000,
                        'currency' => 'COP',
                    ],
                ], 201)
                ->push([
                    'data' => [
                        'id' => 'trx_credits_recovered',
                        'status' => 'APPROVED',
                        'amount_in_cents' => 4000000,
                        'currency' => 'COP',
                    ],
                ], 201),
        ]);

        [$user, $token] = $this->paidUser();

        $this->withToken($token)->postJson('/api/credits/purchases', [
            'credits' => 1000,
            'idempotency_key' => 'credits-purchase-declined',
            'terms_accepted' => true,
        ])->assertPaymentRequired()
            ->assertJsonPath('code', 'CREDIT_PAYMENT_DECLINED')
            ->assertJsonPath('data.payment_order.status', 'declined')
            ->assertJsonPath('data.wallet.available', 0);

        $source = PaymentSource::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertTrue($source->requires_attention);
        $this->assertFalse($source->isChargeable());
        $this->assertSame('payment_declined', $source->last_payment_failure_code);
        $this->assertDatabaseCount('credit_ledger_entries', 0);

        $this->withToken($token)->postJson('/api/credits/purchases', [
            'credits' => 1000,
            'idempotency_key' => 'credits-purchase-after-decline',
            'terms_accepted' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'PAYMENT_METHOD_REQUIRED');

        $source->update(['is_default' => false]);
        $replacement = PaymentSource::create([
            'user_id' => $user->id,
            'provider' => PaymentProvider::Wompi,
            'provider_source_id' => 'replacement-source',
            'type' => 'CARD',
            'status' => 'active',
            'reusable' => true,
            'is_default' => true,
            'verified_at' => now(),
        ]);

        $this->withToken($token)->postJson('/api/credits/purchases', [
            'credits' => 1000,
            'idempotency_key' => 'credits-purchase-recovered',
            'terms_accepted' => true,
        ])->assertCreated()
            ->assertJsonPath('code', 'CREDITS_PURCHASED')
            ->assertJsonPath('data.payment_order.status', 'approved')
            ->assertJsonPath('data.wallet.available', 1000);

        $this->assertSame($replacement->id, $user->subscriptions()->where('active', true)->firstOrFail()->payment_source_id);
        $this->assertTrue($source->fresh()->requires_attention);
        $this->assertFalse($replacement->fresh()->requires_attention);
        $this->assertDatabaseCount('credit_ledger_entries', 1);
        Http::assertSentCount(2);
    }

    public function test_pending_credit_purchase_does_not_grant_credits_or_reject_the_card(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/transactions' => Http::response([
                'data' => [
                    'id' => 'trx_credits_pending',
                    'status' => 'PENDING',
                    'amount_in_cents' => 4000000,
                    'currency' => 'COP',
                ],
            ], 201),
        ]);

        [$user, $token] = $this->paidUser();

        $this->withToken($token)->postJson('/api/credits/purchases', [
            'credits' => 1000,
            'idempotency_key' => 'credits-purchase-pending',
            'terms_accepted' => true,
        ])->assertAccepted()
            ->assertJsonPath('code', 'CREDIT_PURCHASE_PENDING')
            ->assertJsonPath('data.payment_order.status', 'pending')
            ->assertJsonPath('data.wallet.available', 0);

        $source = PaymentSource::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertFalse($source->requires_attention);
        $this->assertTrue($source->isChargeable());
        $this->assertDatabaseCount('credit_ledger_entries', 0);
    }

    public function test_credit_provider_error_does_not_grant_credits_or_reject_the_card(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/transactions' => Http::response([
                'error' => ['type' => 'provider_unavailable'],
            ], 503),
        ]);

        [$user, $token] = $this->paidUser();

        $this->withToken($token)->postJson('/api/credits/purchases', [
            'credits' => 1000,
            'idempotency_key' => 'credits-purchase-provider-error',
            'terms_accepted' => true,
        ])->assertPaymentRequired()
            ->assertJsonPath('code', 'CREDIT_PAYMENT_FAILED')
            ->assertJsonPath('data.payment_order.status', 'error')
            ->assertJsonPath('data.wallet.available', 0);

        $source = PaymentSource::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertFalse($source->requires_attention);
        $this->assertTrue($source->isChargeable());
        $this->assertDatabaseCount('credit_ledger_entries', 0);
    }

    public function test_idempotency_key_cannot_be_reused_for_a_different_package(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/transactions' => Http::response([
                'data' => [
                    'id' => 'trx_credits_idempotency_conflict',
                    'status' => 'APPROVED',
                    'amount_in_cents' => 4000000,
                    'currency' => 'COP',
                ],
            ], 201),
        ]);

        [, $token] = $this->paidUser();
        $base = [
            'idempotency_key' => 'credits-purchase-conflict',
            'terms_accepted' => true,
        ];

        $this->withToken($token)->postJson('/api/credits/purchases', [
            ...$base,
            'credits' => 1000,
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/credits/purchases', [
            ...$base,
            'credits' => 2000,
        ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        Http::assertSentCount(1);
    }

    public function test_idempotency_key_cannot_be_reused_with_a_different_payment_method(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/transactions' => Http::response([
                'data' => [
                    'id' => 'trx_credits_idempotency_method',
                    'status' => 'APPROVED',
                    'amount_in_cents' => 4000000,
                    'currency' => 'COP',
                ],
            ], 201),
        ]);

        [$user, $token] = $this->paidUser();
        $default = PaymentSource::query()->where('user_id', $user->id)->firstOrFail();
        $secondary = PaymentSource::query()->create([
            'user_id' => $user->id,
            'provider' => PaymentProvider::Wompi,
            'provider_source_id' => 'idempotency-secondary',
            'type' => 'CARD',
            'status' => 'active',
            'reusable' => true,
            'is_default' => false,
            'verified_at' => now(),
        ]);
        $base = [
            'credits' => 1000,
            'idempotency_key' => 'credits-method-conflict',
            'terms_accepted' => true,
        ];

        $this->withToken($token)->postJson('/api/credits/purchases', [
            ...$base,
            'payment_source_id' => $default->id,
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/credits/purchases', [
            ...$base,
            'payment_source_id' => $secondary->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        Http::assertSentCount(1);
    }

    public function test_purchase_validates_minimum_and_step(): void
    {
        [, $token] = $this->paidUser();

        $this->withToken($token)->postJson('/api/credits/purchases', [
            'credits' => 100,
            'idempotency_key' => 'credits-too-small',
            'terms_accepted' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('credits');

        $this->withToken($token)->postJson('/api/credits/purchases', [
            'credits' => 1500,
            'idempotency_key' => 'credits-wrong-step',
            'terms_accepted' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('credits');

        $this->withToken($token)->postJson('/api/credits/purchases', [
            'credits' => 101000,
            'idempotency_key' => 'credits-too-large',
            'terms_accepted' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('credits');
    }

    public function test_trial_user_cannot_purchase_credits(): void
    {
        $user = User::factory()->create();
        [$subscription] = $this->createConfiguredSubscription(
            $user,
            SubscriptionPlan::Starter,
            SubscriptionStatus::Trialing,
        );
        $source = $this->paymentSource($user);
        $subscription->update(['payment_source_id' => $source->id]);
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $this->withToken($token)->postJson('/api/credits/purchases', [
            'credits' => 1000,
            'idempotency_key' => 'credits-trial-rejected',
            'terms_accepted' => true,
        ])->assertPaymentRequired()
            ->assertJsonPath('errors.subscription.0', 'An active paid subscription is required to purchase credits.');
    }

    public function test_credit_purchase_returns_structured_payment_method_required_error(): void
    {
        [$user, $token] = $this->paidUser();
        PaymentSource::query()->where('user_id', $user->id)->update([
            'disabled_at' => now(),
            'is_default' => false,
            'reusable' => false,
            'status' => 'disabled',
        ]);

        $this->withToken($token)->postJson('/api/credits/purchases', [
            'credits' => 1000,
            'idempotency_key' => 'credits-payment-method-required',
            'terms_accepted' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'PAYMENT_METHOD_REQUIRED')
            ->assertJsonPath(
                'errors.payment_source.0',
                'A valid active reusable payment method is required to purchase credits.'
            );
    }

    public function test_admin_grant_is_not_treated_as_a_paid_subscription_for_credit_purchases(): void
    {
        $user = User::factory()->create();
        [$subscription] = $this->createConfiguredSubscription(
            $user,
            SubscriptionPlan::Admin,
            SubscriptionStatus::Renewed,
        );
        $source = $this->paymentSource($user);
        $subscription->forceFill([
            'billing_mode' => 'admin_grant',
            'payment_source_id' => $source->id,
        ])->save();
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $this->withToken($token)->postJson('/api/credits/purchases', [
            'credits' => 1000,
            'idempotency_key' => 'credits-admin-grant-rejected',
            'terms_accepted' => true,
        ])->assertPaymentRequired()
            ->assertJsonPath('code', 'SUBSCRIPTION_INACTIVE');
    }

    /**
     * @return array{User,string}
     */
    private function paidUser(): array
    {
        $user = User::factory()->create();
        [$subscription] = $this->createConfiguredSubscription($user);
        $source = $this->paymentSource($user);
        $subscription->update([
            'billing_mode' => 'recurring',
            'payment_source_id' => $source->id,
        ]);
        $token = $user->createToken('test-token', ['payments:create', 'payments:read'])->plainTextToken;

        return [$user, $token];
    }

    private function paymentSource(User $user): PaymentSource
    {
        return PaymentSource::create([
            'user_id' => $user->id,
            'provider' => PaymentProvider::Wompi,
            'provider_source_id' => '3891',
            'type' => 'CARD',
            'status' => 'active',
            'reusable' => true,
            'verified_at' => now(),
        ]);
    }
}
