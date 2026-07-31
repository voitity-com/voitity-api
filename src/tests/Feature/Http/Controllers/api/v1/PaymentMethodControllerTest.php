<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Classes\PaymentService\PaymentManager;
use App\Classes\Subscriptions\PaymentMethodService;
use App\Enums\PaymentProvider;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\PaymentOrder;
use App\Models\PaymentSource;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class PaymentMethodControllerTest extends TestAPI
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.default', 'wompi');
        Config::set('payment.drivers.wompi.environment', 'sandbox');
        Config::set('payment.drivers.wompi.public_key', 'pub_test_key');
        Config::set('payment.drivers.wompi.private_key', 'prv_test_key');
        Config::set('payment.drivers.wompi.integrity_secret', 'test_integrity_key');
        Config::set('payment.drivers.wompi.events_secret', 'test_events_key');
        Config::set('payment.drivers.wompi.api_url', 'https://sandbox.wompi.co/v1');

        app(PaymentManager::class)->forgetDrivers();
    }

    public function test_user_can_add_and_list_a_sanitized_default_payment_method(): void
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
                        'exp_month' => 12,
                        'exp_year' => 2030,
                    ],
                ],
            ], 201),
        ]);

        [$user, $token] = $this->authenticatedUser();
        $response = $this->withToken($token)->postJson('/api/payment-methods', $this->storePayload());

        $response->assertCreated()
            ->assertJsonPath('data.payment_method.brand', 'VISA')
            ->assertJsonPath('data.payment_method.last_four', '4242')
            ->assertJsonPath('data.payment_method.is_default', true)
            ->assertJsonMissingPath('data.payment_method.provider_source_id')
            ->assertJsonMissingPath('data.payment_method.metadata');

        $source = PaymentSource::query()->firstOrFail();
        $this->assertSame($user->id, $source->user_id);
        $this->assertSame('3891', $source->provider_source_id);
        $this->assertNull($source->getRawOriginal('provider_source_id'));
        $this->assertNotNull($source->provider_source_ciphertext);
        $this->assertNotNull($source->provider_source_hash);
        $this->assertTrue($source->is_default);

        $this->withToken($token)
            ->getJson('/api/payment-methods')
            ->assertOk()
            ->assertJsonCount(1, 'data.payment_methods')
            ->assertJsonPath('data.payment_methods.0.last_four', '4242')
            ->assertJsonMissingPath('data.payment_methods.0.provider_source_id');
    }

    public function test_new_payment_method_can_atomically_become_default(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/payment_sources' => Http::response([
                'data' => [
                    'id' => 5000,
                    'type' => 'CARD',
                    'status' => 'AVAILABLE',
                    'public_data' => [
                        'brand' => 'MASTERCARD',
                        'last_four' => '4444',
                        'exp_month' => 12,
                        'exp_year' => 2031,
                    ],
                ],
            ], 201),
        ]);

        [$user, $token] = $this->authenticatedUser();
        $oldDefault = $this->paymentSource($user, 'old-default', true, '1111');
        $subscription = $this->subscription($user, $oldDefault);

        $this->withToken($token)
            ->postJson('/api/payment-methods', [
                ...$this->storePayload(),
                'make_default' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.payment_method.is_default', true)
            ->assertJsonPath('data.payment_method.last_four', '4444');

        $newDefault = PaymentSource::query()->where('card_last_four', '4444')->firstOrFail();
        $this->assertFalse($oldDefault->fresh()->is_default);
        $this->assertTrue($newDefault->is_default);
        $this->assertSame($newDefault->id, $subscription->fresh()->payment_source_id);
    }

    public function test_tokenized_card_brand_is_preferred_over_the_generic_provider_type(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/payment_sources' => Http::response([
                'data' => [
                    'id' => 5001,
                    'type' => 'CARD',
                    'status' => 'AVAILABLE',
                    'public_data' => [
                        'type' => 'CARD',
                        'last_four' => '4242',
                        'exp_month' => 12,
                        'exp_year' => 2031,
                    ],
                ],
            ], 201),
        ]);

        [, $token] = $this->authenticatedUser();

        $this->withToken($token)
            ->postJson('/api/payment-methods', [
                ...$this->storePayload(),
                'metadata' => [
                    'card' => [
                        'brand' => 'VISA',
                        'last_four' => '4242',
                        'exp_month' => 12,
                        'exp_year' => 2031,
                    ],
                    'wompi_environment' => 'sandbox',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.payment_method.brand', 'VISA');
    }

    public function test_user_can_change_default_and_active_subscription_is_synchronized(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $first = $this->paymentSource($user, 'source_1', true, '1111');
        $second = $this->paymentSource($user, 'source_2', false, '2222');
        $subscription = $this->subscription($user, $first);

        $this->withToken($token)
            ->patchJson("/api/payment-methods/{$second->id}/default")
            ->assertOk()
            ->assertJsonPath('data.payment_method.id', $second->id)
            ->assertJsonPath('data.payment_method.is_default', true);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertSame($second->id, $subscription->fresh()->payment_source_id);
    }

    public function test_default_method_cannot_be_removed_and_secondary_method_is_soft_disabled(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $default = $this->paymentSource($user, 'source_default', true, '1111');
        $secondary = $this->paymentSource($user, 'source_secondary', false, '2222');

        $this->withToken($token)
            ->deleteJson("/api/payment-methods/{$default->id}")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Select another default payment method before removing this one.'
            );

        $this->withToken($token)
            ->deleteJson("/api/payment-methods/{$secondary->id}")
            ->assertOk();

        $secondary->refresh();
        $this->assertNotNull($secondary->disabled_at);
        $this->assertFalse($secondary->reusable);
        $this->assertSame('disabled', $secondary->status);

        $this->withToken($token)
            ->getJson('/api/payment-methods')
            ->assertOk()
            ->assertJsonCount(1, 'data.payment_methods');
    }

    public function test_payment_method_with_pending_order_cannot_be_removed(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $default = $this->paymentSource($user, 'source_default', true, '1111');
        $secondary = $this->paymentSource($user, 'source_secondary', false, '2222');

        PaymentOrder::query()->create([
            'user_id' => $user->id,
            'payment_source_id' => $secondary->id,
            'provider' => PaymentProvider::Wompi,
            'reference' => 'pending-method-removal',
            'plan' => SubscriptionPlan::Starter,
            'display_amount_usd' => 10,
            'display_currency' => 'USD',
            'exchange_rate' => 4000,
            'amount_cop' => 40000,
            'amount_in_cents' => 4000000,
            'currency' => 'COP',
            'status' => 'pending',
        ]);

        $this->withToken($token)
            ->deleteJson("/api/payment-methods/{$secondary->id}")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'This payment method has a pending payment and cannot be removed yet.'
            );

        $this->assertNull($secondary->fresh()->disabled_at);
        $this->assertTrue($default->fresh()->is_default);
    }

    public function test_user_cannot_manage_another_users_payment_method(): void
    {
        [, $token] = $this->authenticatedUser();
        $other = User::factory()->create();
        $source = $this->paymentSource($other, 'other_source', true, '9999');

        $this->withToken($token)
            ->patchJson("/api/payment-methods/{$source->id}/default")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Payment method not found.');

        $this->withToken($token)
            ->deleteJson("/api/payment-methods/{$source->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Payment method not found.');
    }

    public function test_expired_payment_method_cannot_be_selected_as_default(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $this->paymentSource($user, 'source_default', true, '1111');
        $expired = $this->paymentSource($user, 'source_expired', false, '2222', [
            'card_exp_month' => 1,
            'card_exp_year' => 2020,
        ]);

        $this->withToken($token)
            ->patchJson("/api/payment-methods/{$expired->id}/default")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Only an active, reusable and non-expired payment method can be selected.'
            );
    }

    public function test_failed_webhook_does_not_degrade_an_existing_verified_payment_method(): void
    {
        $user = User::factory()->create();
        $source = PaymentSource::query()->create([
            'user_id' => $user->id,
            'provider' => PaymentProvider::Wompi,
            'provider_source_id' => 'verified-source-123',
            'type' => 'CARD',
            'card_brand' => 'VISA',
            'card_last_four' => '4242',
            'card_exp_month' => 12,
            'card_exp_year' => now()->year + 2,
            'status' => 'active',
            'reusable' => true,
            'verified_at' => now(),
        ]);

        $updated = app(PaymentMethodService::class)->upsertFromWebhook(
            $user,
            'verified-source-123',
            'CARD',
            false,
            ['status' => 'DECLINED'],
        );

        $this->assertSame($source->id, $updated->id);
        $this->assertSame('active', $updated->status);
        $this->assertTrue($updated->reusable);
        $this->assertSame('VISA', $updated->card_brand);
        $this->assertSame('4242', $updated->card_last_four);
        $this->assertTrue($updated->isChargeable());
    }

    public function test_payment_method_read_endpoint_requires_read_ability(): void
    {
        $user = User::factory()->create();
        $writeToken = $user->createToken('write', ['payments:create'])->plainTextToken;

        $this->withToken($writeToken)->getJson('/api/payment-methods')->assertForbidden();
    }

    public function test_payment_method_write_endpoint_requires_create_ability(): void
    {
        $user = User::factory()->create();
        $readToken = $user->createToken('read', ['payments:read'])->plainTextToken;

        $this->withToken($readToken)->postJson('/api/payment-methods', [])->assertForbidden();
    }

    /**
     * @return array{User,string}
     */
    private function authenticatedUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createToken(
            'test-token',
            ['payments:create', 'payments:read']
        )->plainTextToken;

        return [$user, $token];
    }

    /**
     * @return array<string, mixed>
     */
    private function storePayload(): array
    {
        return [
            'type' => 'CARD',
            'token' => 'tok_test_4242',
            'acceptance_token' => 'acceptance-token',
            'accept_personal_auth' => 'personal-auth-token',
            'session_id' => 'session-1',
            'customer_data' => [
                'device_id' => 'device-1',
                'full_name' => 'Payment Test',
            ],
            'metadata' => [
                'card' => [
                    'brand' => 'VISA',
                    'last_four' => '4242',
                    'exp_month' => 12,
                    'exp_year' => 2030,
                ],
                'wompi_environment' => 'sandbox',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function paymentSource(
        User $user,
        string $providerSourceId,
        bool $default,
        string $lastFour,
        array $overrides = [],
    ): PaymentSource {
        return PaymentSource::query()->create(array_merge([
            'user_id' => $user->id,
            'provider' => PaymentProvider::Wompi,
            'provider_source_id' => $providerSourceId,
            'type' => 'CARD',
            'card_brand' => 'VISA',
            'card_last_four' => $lastFour,
            'card_exp_month' => 12,
            'card_exp_year' => 2030,
            'status' => 'active',
            'reusable' => true,
            'is_default' => $default,
            'verified_at' => now(),
        ], $overrides));
    }

    private function subscription(
        User $user,
        PaymentSource $paymentSource,
    ): Subscription {
        return Subscription::query()->create([
            'user_id' => $user->id,
            'payment_source_id' => $paymentSource->id,
            'plan' => SubscriptionPlan::Starter,
            'billing_mode' => 'recurring',
            'started_at' => now()->subDay(),
            'renews_at' => now()->addMonth(),
            'status' => SubscriptionStatus::First,
            'active' => true,
            'cancel_at_period_end' => false,
            'next_billing_at' => now()->addMonth(),
        ]);
    }
}
