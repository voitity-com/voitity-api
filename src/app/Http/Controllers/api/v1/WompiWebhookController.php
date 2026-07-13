<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\PaymentService\PaymentService;
use App\Classes\Subscriptions\SubscriptionPlanActivator;
use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentProvider;
use App\Http\Controllers\Controller;
use App\Models\PaymentEvent;
use App\Models\PaymentOrder;
use App\Models\PaymentSource;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        SubscriptionPlanActivator $subscriptionPlanActivator
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
            'payload' => $webhook->payload,
        ]);

        if ($paymentEvent->processed_at) {
            return response()->json(['message' => 'Wompi event already processed.']);
        }

        if (! $webhook->isValidSignature || ! $paymentOrder) {
            $paymentEvent->processed_at = now();
            $paymentEvent->save();

            return response()->json(['message' => 'Wompi event ignored.']);
        }

        if (! $this->matchesPaymentOrder($paymentOrder, $webhook->amountInCents, $webhook->currency)) {
            $paymentEvent->payment_order_id = $paymentOrder->id;
            $paymentEvent->processed_at = now();
            $paymentEvent->save();

            return response()->json(['message' => 'Wompi event ignored.']);
        }

        $notificationOrder = null;
        $statusChanged = false;

        DB::transaction(function () use (
            $paymentOrder,
            $paymentEvent,
            $webhook,
            $subscriptionPlanActivator,
            &$notificationOrder,
            &$statusChanged
        ): void {
            /** @var PaymentOrder $order */
            $order = PaymentOrder::whereKey($paymentOrder->id)->lockForUpdate()->firstOrFail();
            $previousStatus = $order->status;

            $order->provider_transaction_id = $webhook->providerTransactionId;
            $order->wompi_status = $webhook->providerStatus;
            $order->raw_provider_payload = $webhook->payload;
            $order->status = PaymentOrderStatus::from($webhook->status);
            $statusChanged = $previousStatus !== $order->status;

            if ($webhook->paymentSourceProviderId) {
                $paymentSource = PaymentSource::updateOrCreate([
                    'provider' => PaymentProvider::Wompi->value,
                    'provider_source_id' => $webhook->paymentSourceProviderId,
                ], [
                    'user_id' => $order->user_id,
                    'type' => is_scalar($webhook->transaction['payment_method_type'] ?? null)
                        ? (string) $webhook->transaction['payment_method_type']
                        : null,
                    'status' => $order->status === PaymentOrderStatus::Approved ? 'active' : 'pending',
                    'reusable' => true,
                    'metadata' => ['transaction' => $webhook->transaction],
                    'verified_at' => $order->status === PaymentOrderStatus::Approved ? now() : null,
                    'last_used_at' => now(),
                ]);

                $order->payment_source_id = $paymentSource->id;
            }

            if ($order->status === PaymentOrderStatus::Approved && ! $order->paid_at) {
                $order->paid_at = now();
            }

            $order->save();

            if ($order->status === PaymentOrderStatus::Approved) {
                $subscriptionPlanActivator->activateForPaymentOrder($order);
            }

            $paymentEvent->payment_order_id = $order->id;
            $paymentEvent->processed_at = now();
            $paymentEvent->save();

            $notificationOrder = $order->fresh(['user']);
        });

        if ($statusChanged && $notificationOrder instanceof PaymentOrder) {
            $this->dispatchPaymentNotifications($notificationOrder);
        }

        return response()->json(['message' => 'Wompi event processed successfully.']);
    }

    private function matchesPaymentOrder(PaymentOrder $paymentOrder, ?int $amountInCents, ?string $currency): bool
    {
        return $amountInCents === $paymentOrder->amount_in_cents
            && $currency === $paymentOrder->currency->value;
    }

    private function dispatchPaymentNotifications(PaymentOrder $paymentOrder): void
    {
        $paymentOrder->loadMissing('user');
        $user = $paymentOrder->user;

        if (! $user) {
            return;
        }

        $dispatcher = app(NotificationDispatcher::class);
        $data = $this->notificationDataForOrder($paymentOrder);

        if ($paymentOrder->status === PaymentOrderStatus::Approved) {
            if ($paymentOrder->billing_reason === 'subscription_renewal') {
                $dispatcher->send($user, 'successful_subscription_renewal', $data);
            } else {
                $dispatcher->send($user, 'successful_plan_purchase', $data);
            }

            return;
        }

        if ($paymentOrder->status === PaymentOrderStatus::Pending) {
            $dispatcher->sendInApp($user, 'payment_pending', $data);

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
            'plan' => $paymentOrder->plan->value,
            'amount' => sprintf('USD %.2f', (float) $paymentOrder->display_amount_usd),
            'payment_order_id' => $paymentOrder->id,
            'reference' => $paymentOrder->reference,
        ];
    }
}
