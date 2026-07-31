<?php

namespace App\Classes\Subscriptions;

use App\Enums\CreditLedgerEntryType;
use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentProductType;
use App\Exceptions\Subscriptions\SubscriptionEntitlementException;
use App\Models\CreditLedgerEntry;
use App\Models\CreditWallet;
use App\Models\PaymentOrder;
use App\Models\SubscriptionUse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CreditWalletService
{
    public function walletForUser(User|int $user): CreditWallet
    {
        $userId = $user instanceof User ? $user->id : $user;

        $wallet = CreditWallet::firstOrCreate(['user_id' => $userId]);

        return $wallet->wasRecentlyCreated ? $wallet->fresh() : $wallet;
    }

    public function lockedWalletForUser(User|int $user): CreditWallet
    {
        $userId = $user instanceof User ? $user->id : $user;
        User::whereKey($userId)->lockForUpdate()->firstOrFail();
        $wallet = CreditWallet::where('user_id', $userId)->lockForUpdate()->first();

        if ($wallet instanceof CreditWallet) {
            return $wallet;
        }

        return CreditWallet::create([
            'user_id' => $userId,
            'available_units' => 0,
            'reserved_units' => 0,
            'debt_units' => 0,
            'lifetime_purchased_units' => 0,
            'lifetime_consumed_units' => 0,
        ]);
    }

    public function assertCanReserve(CreditWallet $wallet, int $units): void
    {
        if ($units <= 0) {
            return;
        }

        if ($wallet->debt_units > 0) {
            throw new SubscriptionEntitlementException(
                'Purchased credits are unavailable while the account has a reversed-payment balance.',
                ['purchased_credits' => ['Purchased credits are unavailable because a payment was reversed.']]
            );
        }

        if ($wallet->available_units < $units) {
            throw new SubscriptionEntitlementException(
                'Insufficient purchased credits.',
                ['purchased_credits' => ['Insufficient purchased credits.']]
            );
        }
    }

    public function reserveLocked(
        CreditWallet $wallet,
        SubscriptionUse $use,
        int $units,
        int $sequence,
    ): void {
        if ($units <= 0) {
            return;
        }

        $this->assertCanReserve($wallet, $units);

        $wallet->available_units -= $units;
        $wallet->reserved_units += $units;
        $wallet->save();

        $this->entry(
            wallet: $wallet,
            type: CreditLedgerEntryType::Reserve,
            amountUnits: -$units,
            idempotencyKey: "usage-reserve:{$use->id}:{$sequence}",
            use: $use,
        );
    }

    public function consumeLocked(
        CreditWallet $wallet,
        SubscriptionUse $use,
        int $units,
        int $sequence,
    ): void {
        if ($units <= 0) {
            return;
        }

        if ($wallet->reserved_units < $units) {
            throw new RuntimeException('Credit wallet reserved balance is inconsistent.');
        }

        $wallet->reserved_units -= $units;
        $wallet->lifetime_consumed_units += $units;
        $wallet->save();

        $this->entry(
            wallet: $wallet,
            type: CreditLedgerEntryType::Consume,
            amountUnits: 0,
            idempotencyKey: "usage-consume:{$use->id}:{$sequence}",
            use: $use,
        );
    }

    public function releaseLocked(
        CreditWallet $wallet,
        SubscriptionUse $use,
        int $units,
        int $sequence,
        bool $wasFinalized = false,
    ): void {
        if ($units <= 0) {
            return;
        }

        if ($wasFinalized) {
            if ($wallet->lifetime_consumed_units < $units) {
                throw new RuntimeException('Credit wallet consumed balance is inconsistent.');
            }

            $wallet->lifetime_consumed_units -= $units;
        } else {
            if ($wallet->reserved_units < $units) {
                throw new RuntimeException('Credit wallet reserved balance is inconsistent.');
            }

            $wallet->reserved_units -= $units;
        }

        $debtPayment = min($wallet->debt_units, $units);
        $wallet->debt_units -= $debtPayment;
        $wallet->available_units += $units - $debtPayment;
        $wallet->save();

        $this->entry(
            wallet: $wallet,
            type: CreditLedgerEntryType::Release,
            amountUnits: $units,
            idempotencyKey: "usage-release:{$use->id}:{$sequence}",
            use: $use,
            metadata: [
                'debt_units_paid' => $debtPayment,
                'released_units' => $units,
            ],
        );
    }

    public function grantForPaymentOrder(PaymentOrder $paymentOrder): CreditWallet
    {
        return DB::transaction(function () use ($paymentOrder): CreditWallet {
            /** @var PaymentOrder $order */
            $order = PaymentOrder::whereKey($paymentOrder->id)->lockForUpdate()->firstOrFail();

            if (
                $order->product_type !== PaymentProductType::CreditPack
                || $order->status !== PaymentOrderStatus::Approved
                || $order->credit_units <= 0
            ) {
                throw new RuntimeException('Payment order is not an approved credit purchase.');
            }

            $idempotencyKey = "credit-purchase:payment-order:{$order->id}";
            $existing = CreditLedgerEntry::where('idempotency_key', $idempotencyKey)->first();
            $wallet = $this->lockedWalletForUser((int) $order->user_id);

            if ($existing instanceof CreditLedgerEntry) {
                return $wallet;
            }

            $units = (int) $order->credit_units;
            $debtPayment = min($wallet->debt_units, $units);
            $wallet->debt_units -= $debtPayment;
            $wallet->available_units += $units - $debtPayment;
            $wallet->lifetime_purchased_units += $units;
            $wallet->save();

            $this->entry(
                wallet: $wallet,
                type: CreditLedgerEntryType::Purchase,
                amountUnits: $units,
                idempotencyKey: $idempotencyKey,
                paymentOrder: $order,
                metadata: [
                    'credits' => CreditAmount::unitsToCredits($units),
                    'debt_units_paid' => $debtPayment,
                    'display_amount_usd' => $order->display_amount_usd,
                ],
            );

            Log::info('Purchased credits granted.', [
                'credit_units' => $units,
                'payment_order_id' => $order->id,
                'user_id' => $order->user_id,
            ]);

            return $wallet;
        });
    }

    public function reverseForPaymentOrder(PaymentOrder $paymentOrder): CreditWallet
    {
        return DB::transaction(function () use ($paymentOrder): CreditWallet {
            /** @var PaymentOrder $order */
            $order = PaymentOrder::whereKey($paymentOrder->id)->lockForUpdate()->firstOrFail();
            $purchaseKey = "credit-purchase:payment-order:{$order->id}";
            $reversalKey = "credit-reversal:payment-order:{$order->id}";
            $purchase = CreditLedgerEntry::where('idempotency_key', $purchaseKey)->first();
            $existingReversal = CreditLedgerEntry::where('idempotency_key', $reversalKey)->first();
            $wallet = $this->lockedWalletForUser((int) $order->user_id);

            if (! $purchase instanceof CreditLedgerEntry || $existingReversal instanceof CreditLedgerEntry) {
                return $wallet;
            }

            $units = max(0, (int) $order->credit_units);
            $availableRemoved = min($wallet->available_units, $units);
            $wallet->available_units -= $availableRemoved;
            $wallet->debt_units += $units - $availableRemoved;
            $wallet->save();

            $this->entry(
                wallet: $wallet,
                type: CreditLedgerEntryType::Reversal,
                amountUnits: -$units,
                idempotencyKey: $reversalKey,
                paymentOrder: $order,
                metadata: [
                    'available_units_removed' => $availableRemoved,
                    'debt_units_created' => $units - $availableRemoved,
                ],
            );

            Log::warning('Purchased credits reversed.', [
                'credit_units' => $units,
                'debt_units' => $wallet->debt_units,
                'payment_order_id' => $order->id,
                'user_id' => $order->user_id,
            ]);

            return $wallet;
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function entry(
        CreditWallet $wallet,
        CreditLedgerEntryType $type,
        int $amountUnits,
        string $idempotencyKey,
        ?SubscriptionUse $use = null,
        ?PaymentOrder $paymentOrder = null,
        array $metadata = [],
    ): CreditLedgerEntry {
        return CreditLedgerEntry::create([
            'credit_wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'subscription_use_id' => $use?->id,
            'payment_order_id' => $paymentOrder?->id,
            'type' => $type,
            'amount_units' => $amountUnits,
            'available_units_after' => $wallet->available_units,
            'reserved_units_after' => $wallet->reserved_units,
            'debt_units_after' => $wallet->debt_units,
            'idempotency_key' => $idempotencyKey,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
