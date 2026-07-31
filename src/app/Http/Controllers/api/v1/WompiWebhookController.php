<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\PaymentService\PaymentOperationsMonitor;
use App\Classes\PaymentService\PaymentPayloadSanitizer;
use App\Classes\PaymentService\PaymentService;
use App\Classes\Subscriptions\CreditAmount;
use App\Classes\Subscriptions\CreditWalletService;
use App\Classes\Subscriptions\PaymentMethodService;
use App\Classes\Subscriptions\SubscriptionBillingService;
use App\Classes\Subscriptions\SubscriptionPlanActivator;
use App\Classes\Subscriptions\SubscriptionTrialService;
use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentProductType;
use App\Enums\PaymentProvider;
use App\Http\Controllers\Controller;
use App\Models\PaymentEvent;
use App\Models\PaymentOrder;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WompiWebhookController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/payments/wompi/events",
     *     summary="Receive Wompi payment events",
     *     tags={"Payments"},
     *
     *     @OA\Response(response=200, description="Wompi event received")
     * )
     */
    public function handle(
        Request $request,
        PaymentService $paymentService,
        SubscriptionPlanActivator $subscriptionPlanActivator,
        SubscriptionBillingService $subscriptionBilling,
        SubscriptionTrialService $trialService,
        CreditWalletService $creditWallets,
        PaymentMethodService $paymentMethods,
        PaymentPayloadSanitizer $payloadSanitizer,
        PaymentOperationsMonitor $operationsMonitor,
    ): JsonResponse {
        $webhook = $paymentService->parseWebhook(
            ['x-event-checksum' => $request->header('X-Event-Checksum')],
            $request->getContent(),
        );

        $paymentOrder = $webhook->reference
            ? PaymentOrder::where('reference', $webhook->reference)->first()
            : null;

        $paymentEvent = PaymentEvent::firstOrCreate([
            'provider' => PaymentProvider::Wompi,
            'provider_event_id' => $webhook->providerEventId,
        ], [
            'payment_order_id' => $paymentOrder?->id,
            'event_type' => $webhook->event,
            'checksum' => $webhook->checksum,
            'is_valid_signature' => $webhook->isValidSignature,
            'payload' => $payloadSanitizer->webhook($webhook->payload),
        ]);

        if ($paymentEvent->processed_at) {
            Log::info('Duplicate Wompi event acknowledged.', [
                'payment_event_id' => $paymentEvent->id,
                'provider_event_id' => $webhook->providerEventId,
            ]);

            return response()->json(['message' => 'Wompi event already processed.']);
        }

        if (! $webhook->isValidSignature || ! $paymentOrder) {
            $paymentEvent->processed_at = now();
            $paymentEvent->save();

            Log::warning('Wompi event ignored.', [
                'has_payment_order' => $paymentOrder instanceof PaymentOrder,
                'is_valid_signature' => $webhook->isValidSignature,
                'payment_event_id' => $paymentEvent->id,
                'provider_event_id' => $webhook->providerEventId,
            ]);

            return response()->json(['message' => 'Wompi event ignored.']);
        }

        if (! $this->matchesPaymentOrder($paymentOrder, $webhook->amountInCents, $webhook->currency)) {
            $paymentEvent->payment_order_id = $paymentOrder->id;
            $paymentEvent->processed_at = now();
            $paymentEvent->save();

            Log::warning('Wompi event amount or currency mismatch.', [
                'payment_event_id' => $paymentEvent->id,
                'payment_order_id' => $paymentOrder->id,
                'provider_event_id' => $webhook->providerEventId,
            ]);

            return response()->json(['message' => 'Wompi event ignored.']);
        }

        $operationsMonitor->recordValidWebhook();

        $notificationOrder = null;
        $statusChanged = false;
        $statusTransitionIgnored = false;
        $creditReversed = false;

        DB::transaction(function () use (
            $paymentOrder,
            $paymentEvent,
            $webhook,
            $subscriptionPlanActivator,
            $subscriptionBilling,
            $trialService,
            $creditWallets,
            $paymentMethods,
            $payloadSanitizer,
            &$notificationOrder,
            &$statusChanged,
            &$statusTransitionIgnored,
            &$creditReversed
        ): void {
            /** @var PaymentOrder $order */
            $order = PaymentOrder::whereKey($paymentOrder->id)->lockForUpdate()->firstOrFail();
            $previousStatus = $order->status;
            $incomingStatus = PaymentOrderStatus::from($webhook->status);

            if (! $this->canApplyStatusTransition($previousStatus, $incomingStatus)) {
                $statusTransitionIgnored = true;
                $paymentEvent->payment_order_id = $order->id;
                $paymentEvent->processed_at = now();
                $paymentEvent->save();

                Log::warning('Stale Wompi payment status transition ignored.', [
                    'current_status' => $previousStatus->value,
                    'incoming_status' => $incomingStatus->value,
                    'payment_event_id' => $paymentEvent->id,
                    'payment_order_id' => $order->id,
                    'provider_event_id' => $webhook->providerEventId,
                ]);

                return;
            }

            $order->provider_transaction_id = $webhook->providerTransactionId;
            $order->wompi_status = $webhook->providerStatus;
            $order->raw_provider_payload = $payloadSanitizer->webhook($webhook->payload);
            $order->status = $incomingStatus;
            $statusChanged = $previousStatus !== $order->status;

            if ($webhook->paymentSourceProviderId) {
                $paymentSource = $paymentMethods->upsertFromWebhook(
                    $order->user,
                    $webhook->paymentSourceProviderId,
                    is_scalar($webhook->transaction['payment_method_type'] ?? null)
                        ? (string) $webhook->transaction['payment_method_type']
                        : null,
                    $order->status === PaymentOrderStatus::Approved,
                    $webhook->transaction,
                );

                $paymentSource->forceFill(['last_used_at' => now()])->save();

                $order->payment_source_id = $paymentSource->id;
            }

            if (
                $order->product_type === PaymentProductType::Subscription
                && $order->status === PaymentOrderStatus::Approved
                && $order->billing_reason === 'trial_setup'
                && ! $this->trialHasRequiredPaymentSource($order)
            ) {
                $order->status = PaymentOrderStatus::Error;
                $order->raw_provider_payload = array_merge($order->raw_provider_payload ?? [], [
                    'local_error' => 'trial_payment_source_required',
                ]);
            }

            if ($order->status === PaymentOrderStatus::Approved && ! $order->paid_at) {
                $order->paid_at = now();
            }

            $order->save();

            if ($order->status === PaymentOrderStatus::Declined) {
                $paymentMethods->markRejectedAfterDeclinedPayment($order);
            } elseif ($order->status === PaymentOrderStatus::Approved) {
                $paymentMethods->clearFailureAfterApprovedPayment($order);
            }

            if ($order->product_type === PaymentProductType::CreditPack) {
                if ($order->status === PaymentOrderStatus::Approved) {
                    $creditWallets->grantForPaymentOrder($order);
                } elseif ($previousStatus === PaymentOrderStatus::Approved) {
                    $creditWallets->reverseForPaymentOrder($order);
                    $creditReversed = true;
                }
            } elseif ($order->status === PaymentOrderStatus::Approved && $order->billing_reason === 'trial_setup') {
                $trialService->activateTrialFromPaymentOrder($order);
            } elseif ($order->status === PaymentOrderStatus::Approved) {
                $subscriptionPlanActivator->activateForPaymentOrder($order);
            } elseif (
                $order->recurring
                && in_array($order->billing_reason, ['subscription_renewal', 'trial_conversion'], true)
                && ! in_array($order->status, [PaymentOrderStatus::Pending, PaymentOrderStatus::Approved], true)
            ) {
                $subscriptionBilling->recordFailedPaymentOrder($order);
            }

            $paymentEvent->payment_order_id = $order->id;
            $paymentEvent->processed_at = now();
            $paymentEvent->save();

            $notificationOrder = $order->fresh(['user']);
        });

        if ($statusChanged && $notificationOrder instanceof PaymentOrder) {
            $this->dispatchPaymentNotifications($notificationOrder, $creditReversed);
        }

        Log::info('Wompi event processed.', [
            'credit_reversed' => $creditReversed,
            'payment_event_id' => $paymentEvent->id,
            'payment_order_id' => $paymentOrder->id,
            'provider_event_id' => $webhook->providerEventId,
            'status_transition_ignored' => $statusTransitionIgnored,
            'status_changed' => $statusChanged,
        ]);

        return response()->json(['message' => 'Wompi event processed successfully.']);
    }

    private function matchesPaymentOrder(PaymentOrder $paymentOrder, ?int $amountInCents, ?string $currency): bool
    {
        return $amountInCents === $paymentOrder->amount_in_cents
            && $currency === $paymentOrder->currency->value;
    }

    private function canApplyStatusTransition(
        PaymentOrderStatus $current,
        PaymentOrderStatus $incoming,
    ): bool {
        if ($current === $incoming || $current === PaymentOrderStatus::Pending) {
            return true;
        }

        if ($current === PaymentOrderStatus::Approved) {
            return $incoming === PaymentOrderStatus::Voided;
        }

        if ($current === PaymentOrderStatus::Voided) {
            return false;
        }

        return $incoming === PaymentOrderStatus::Approved;
    }

    private function trialHasRequiredPaymentSource(PaymentOrder $paymentOrder): bool
    {
        if (! (bool) config('subscriptions.trial.requires_payment_source', true)) {
            return true;
        }

        return $paymentOrder->payment_source_id !== null;
    }

    private function dispatchPaymentNotifications(
        PaymentOrder $paymentOrder,
        bool $creditReversed = false,
    ): void {
        $paymentOrder->loadMissing('user');
        $user = $paymentOrder->user;

        if (! $user) {
            return;
        }

        $dispatcher = app(NotificationDispatcher::class);
        $data = $this->notificationDataForOrder($paymentOrder);

        if ($paymentOrder->status === PaymentOrderStatus::Approved) {
            if ($paymentOrder->product_type === PaymentProductType::CreditPack) {
                $dispatcher->send($user, 'credits_purchased', $data);

                return;
            }

            match ($paymentOrder->billing_reason) {
                'trial_setup' => $dispatcher->send($user, 'trial_started', $data),
                'trial_conversion' => $dispatcher->send($user, 'trial_converted_to_paid', $data),
                'subscription_renewal' => $dispatcher->send($user, 'successful_subscription_renewal', $data),
                default => $dispatcher->send($user, 'successful_plan_purchase', $data),
            };

            return;
        }

        if ($paymentOrder->status === PaymentOrderStatus::Pending) {
            $dispatcher->sendInApp($user, 'payment_pending', $data);

            return;
        }

        if ($creditReversed) {
            $dispatcher->send($user, 'credits_reversed', $data);

            return;
        }

        if (in_array($paymentOrder->billing_reason, ['trial_setup', 'trial_conversion'], true)) {
            $dispatcher->send($user, 'trial_payment_failed', $data);

            return;
        }

        if ($paymentOrder->billing_reason === 'subscription_renewal') {
            $dispatcher->send($user, 'failed_subscription_renewal', $data);

            return;
        }

        $dispatcher->send($user, 'failed_payment', $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationDataForOrder(PaymentOrder $paymentOrder): array
    {
        return [
            'plan' => $paymentOrder->plan?->value ?? 'credits',
            'credits' => number_format(CreditAmount::unitsToCredits((int) $paymentOrder->credit_units)),
            'amount' => sprintf('USD %.2f', (float) $paymentOrder->display_amount_usd),
            'payment_order_id' => $paymentOrder->id,
            'reference' => $paymentOrder->reference,
        ];
    }
}
