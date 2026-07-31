<?php

namespace Tests\Unit\Classes\Subscriptions;

use App\Classes\Subscriptions\CreditAmount;
use App\Classes\Subscriptions\CreditWalletService;
use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentProductType;
use App\Enums\PaymentProvider;
use App\Enums\SubscriptionUsageType;
use App\Models\CreditLedgerEntry;
use App\Models\PaymentOrder;
use App\Models\User;
use Tests\Support\CreatesSubscriptionScenarios;
use Tests\TestCase;

class CreditWalletServiceTest extends TestCase
{
    use CreatesSubscriptionScenarios;

    public function test_approved_order_is_granted_only_once(): void
    {
        $user = User::factory()->create();
        $order = $this->creditOrder($user, PaymentOrderStatus::Approved);
        $service = app(CreditWalletService::class);

        $first = $service->grantForPaymentOrder($order);
        $second = $service->grantForPaymentOrder($order);

        $this->assertSame(CreditAmount::creditsToUnits(1000), $first->fresh()->available_units);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CreditLedgerEntry::where('type', 'purchase')->count());
    }

    public function test_reversal_removes_available_credits_and_creates_debt_for_consumed_amount(): void
    {
        $user = User::factory()->create();
        $order = $this->creditOrder($user, PaymentOrderStatus::Approved);
        $service = app(CreditWalletService::class);
        $wallet = $service->grantForPaymentOrder($order);
        $wallet->update([
            'available_units' => CreditAmount::creditsToUnits(300),
            'lifetime_consumed_units' => CreditAmount::creditsToUnits(700),
        ]);

        $reversed = $service->reverseForPaymentOrder($order);
        $service->reverseForPaymentOrder($order);

        $this->assertSame(0, $reversed->fresh()->available_units);
        $this->assertSame(CreditAmount::creditsToUnits(700), $reversed->fresh()->debt_units);
        $this->assertSame(1, CreditLedgerEntry::where('type', 'reversal')->count());
    }

    public function test_released_reservation_offsets_debt_created_by_payment_reversal(): void
    {
        $user = User::factory()->create();
        [, $limit] = $this->createConfiguredSubscription($user);
        $limit->update(['chat_messages_remaining' => 0]);
        $order = $this->creditOrder($user, PaymentOrderStatus::Approved);
        $wallets = app(CreditWalletService::class);
        $wallet = $wallets->grantForPaymentOrder($order);
        $recorder = app(SubscriptionUsageRecorder::class);

        $recorder->reserve(
            userId: $user->id,
            usageType: SubscriptionUsageType::ChatMessageReceived,
            amounts: ['chat_messages' => 1],
            idempotencyKey: 'wallet-reversal-reservation',
        );
        $wallets->reverseForPaymentOrder($order);

        $this->assertSame(0, $wallet->fresh()->available_units);
        $this->assertSame(170, $wallet->fresh()->reserved_units);
        $this->assertSame(170, $wallet->fresh()->debt_units);

        $this->assertTrue($recorder->release('wallet-reversal-reservation'));
        $this->assertSame(0, $wallet->fresh()->available_units);
        $this->assertSame(0, $wallet->fresh()->reserved_units);
        $this->assertSame(0, $wallet->fresh()->debt_units);
    }

    private function creditOrder(User $user, PaymentOrderStatus $status): PaymentOrder
    {
        return PaymentOrder::create([
            'user_id' => $user->id,
            'provider' => PaymentProvider::Wompi,
            'reference' => 'CREDITS-'.$user->id,
            'product_type' => PaymentProductType::CreditPack,
            'product_code' => 'credits-1000',
            'credit_units' => CreditAmount::creditsToUnits(1000),
            'purchase_idempotency_key' => 'wallet-test-'.$user->id,
            'plan' => null,
            'recurring' => false,
            'billing_reason' => 'credit_purchase',
            'display_amount_usd' => 10,
            'display_currency' => 'USD',
            'exchange_rate' => 4000,
            'amount_cop' => 40000,
            'amount_in_cents' => 4000000,
            'currency' => 'COP',
            'status' => $status,
            'paid_at' => $status === PaymentOrderStatus::Approved ? now() : null,
        ]);
    }
}
