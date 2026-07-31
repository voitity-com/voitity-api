<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\PaymentService\PaymentService;
use App\Classes\PaymentService\PaymentSourceCreateRequest;
use App\Classes\Subscriptions\CustomerTermsAcceptance;
use App\Classes\Subscriptions\PaymentMethodService;
use App\Classes\Subscriptions\SubscriptionBillingService;
use App\Classes\Subscriptions\SubscriptionPaymentSourceService;
use App\Classes\Subscriptions\SubscriptionTrialService;
use App\Enums\PaymentOrderStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Subscriptions\SubscriptionPaymentRecoveryException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\StartSubscriptionPaymentSourceRequest;
use App\Http\Requests\Payments\StartSubscriptionTrialRequest;
use App\Http\Responses\Payments\PaymentMethodResponse;
use App\Http\Responses\Payments\PaymentOrderResponse;
use App\Models\PaymentOrder;
use App\Models\PaymentSource;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SubscriptionActionsController extends Controller
{
    public function paymentSourceSetup(PaymentService $paymentService): JsonResponse
    {
        return $this->paymentSourceSetupResponse(
            $paymentService,
            'Subscription payment source setup retrieved successfully.',
        );
    }

    public function trialPaymentSourceSetup(PaymentService $paymentService): JsonResponse
    {
        return $this->paymentSourceSetupResponse(
            $paymentService,
            'Subscription trial payment source setup retrieved successfully.',
        );
    }

    public function startSubscriptionWithPaymentSource(
        StartSubscriptionPaymentSourceRequest $request,
        PaymentService $paymentService,
        SubscriptionPaymentSourceService $subscriptionPaymentSourceService,
        PaymentMethodService $paymentMethods,
    ): JsonResponse {
        try {
            /** @var User $user */
            $user = $request->user();
            $plan = SubscriptionPlan::from((string) $request->validated('plan'));
            $paymentSourceId = $request->validated('payment_source_id');
            $terms = new CustomerTermsAcceptance(now());

            if (is_numeric($paymentSourceId)) {
                $result = $subscriptionPaymentSourceService->startSubscriptionWithExistingPaymentSource(
                    $user,
                    $plan,
                    $paymentService,
                    $paymentMethods->sourceForUser($user, (int) $paymentSourceId),
                    $terms,
                );
            } else {
                /** @var array<string, mixed> $sourceData */
                $sourceData = $request->validated('payment_source');
                $result = $subscriptionPaymentSourceService->startSubscriptionWithPaymentSource(
                    $user,
                    $plan,
                    $paymentService,
                    $this->paymentSourceCreateRequest($user, $sourceData),
                    $terms,
                );
            }

            return response()->json([
                'message' => 'Subscription payment source checkout processed successfully.',
                'data' => [
                    'subscription' => $result['subscription'] instanceof Subscription
                        ? $this->subscriptionPayload($result['subscription'])
                        : null,
                    'payment_source' => $this->paymentSourcePayload($result['payment_source']),
                    'payment_order' => (new PaymentOrderResponse($result['payment_order']))->toArray(),
                ],
            ], 201);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    private function paymentSourceSetupResponse(PaymentService $paymentService, string $message): JsonResponse
    {
        try {
            return response()->json([
                'message' => $message,
                'data' => $paymentService->paymentSourceSetup()->toArray(),
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        }
    }

    public function startTrial(
        StartSubscriptionTrialRequest $request,
        PaymentService $paymentService,
        SubscriptionTrialService $trialService,
        PaymentMethodService $paymentMethods,
    ): JsonResponse {
        try {
            /** @var User $user */
            $user = $request->user();
            $plan = SubscriptionPlan::from((string) $request->validated('plan'));
            $paymentSourceId = $request->validated('payment_source_id');
            $terms = new CustomerTermsAcceptance(now());

            if (is_numeric($paymentSourceId)) {
                $trial = $trialService->startTrialWithExistingPaymentSource(
                    $user,
                    $plan,
                    $paymentMethods->sourceForUser($user, (int) $paymentSourceId),
                    $terms,
                );
            } else {
                /** @var array<string, mixed> $sourceData */
                $sourceData = $request->validated('payment_source');
                $trial = $trialService->startTrialWithPaymentSource(
                    $user,
                    $plan,
                    $paymentService,
                    $this->paymentSourceCreateRequest($user, $sourceData),
                    $terms,
                );
            }

            return response()->json([
                'message' => 'Subscription trial started successfully.',
                'data' => [
                    'subscription' => $this->subscriptionPayload($trial['subscription']),
                    'payment_source' => $this->paymentSourcePayload($trial['payment_source']),
                    'payment_order' => (new PaymentOrderResponse($trial['payment_order']))->toArray(),
                ],
            ], 201);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function cancelTrial(Request $request, SubscriptionTrialService $trialService): JsonResponse
    {
        return $this->subscriptionAction($request, function (User $user) use ($trialService): Subscription {
            return $trialService->cancelTrial($user);
        }, 'Subscription trial cancellation scheduled.');
    }

    public function cancelRenewal(Request $request, SubscriptionTrialService $trialService): JsonResponse
    {
        return $this->subscriptionAction($request, function (User $user) use ($trialService): Subscription {
            return $trialService->cancelRenewal($user);
        }, 'Subscription renewal cancellation scheduled.');
    }

    public function reactivateRenewal(Request $request, SubscriptionTrialService $trialService): JsonResponse
    {
        return $this->subscriptionAction($request, function (User $user) use ($trialService): Subscription {
            return $trialService->reactivateRenewal($user);
        }, 'Subscription renewal reactivated.');
    }

    /**
     * @OA\Get(
     *     path="/api/subscription/billing-state",
     *     summary="Get active or recoverable subscription billing state",
     *     tags={"Subscriptions"},
     *     security={{"sanctum":{"payments:read"}}},
     *
     *     @OA\Response(response=200, description="Billing state retrieved"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Missing token ability")
     * )
     */
    public function billingState(
        Request $request,
        PaymentMethodService $paymentMethods,
        SubscriptionBillingService $billing,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $subscription = Subscription::query()
            ->where('user_id', $user->id)
            ->where(function ($query): void {
                $query
                    ->where('active', true)
                    ->orWhereNotNull('payment_failure_code');
            })
            ->orderByDesc('active')
            ->latest('started_at')
            ->first();
        $defaultMethod = $paymentMethods->forUser($user)
            ->first(fn (PaymentSource $source): bool => (bool) $source->is_default);
        $lastOrder = $subscription instanceof Subscription
            ? PaymentOrder::query()
                ->where(function ($query) use ($subscription): void {
                    $query
                        ->where('source_subscription_id', $subscription->id)
                        ->orWhere('subscription_id', $subscription->id);
                })
                ->latest('id')
                ->first()
            : null;
        $pendingOrder = $subscription instanceof Subscription
            ? PaymentOrder::query()
                ->where('source_subscription_id', $subscription->id)
                ->where('status', PaymentOrderStatus::Pending)
                ->latest('id')
                ->first()
            : null;
        $recoveryRequired = $subscription instanceof Subscription
            && ! $subscription->active
            && $subscription->status === SubscriptionStatus::PastDue
            && filled($subscription->payment_failure_code);
        $automaticRetriesRemaining = $subscription instanceof Subscription
            ? max(0, $billing->maximumAutomaticAttempts() - (int) $subscription->payment_retry_count)
            : 0;

        Log::info('Subscription billing state retrieved.', [
            'payment_recovery_required' => $recoveryRequired,
            'subscription_id' => $subscription?->id,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Subscription billing state retrieved successfully.',
            'data' => [
                'subscription' => $subscription instanceof Subscription
                    ? $this->subscriptionPayload($subscription)
                    : null,
                'payment_recovery' => [
                    'required' => $recoveryRequired,
                    'reason_code' => $subscription?->payment_failure_code,
                    'failed_at' => $subscription?->payment_failed_at?->toJSON(),
                    'retry_count' => (int) ($subscription?->payment_retry_count ?? 0),
                    'next_retry_at' => $subscription?->next_payment_retry_at?->toJSON(),
                    'automatic_retries_remaining' => $automaticRetriesRemaining,
                    'has_pending_payment' => $pendingOrder instanceof PaymentOrder,
                    'can_retry_now' => $recoveryRequired
                        && $defaultMethod instanceof PaymentSource
                        && $defaultMethod->isChargeable()
                        && ! $pendingOrder instanceof PaymentOrder,
                ],
                'payment_method' => $defaultMethod instanceof PaymentSource
                    ? (new PaymentMethodResponse($defaultMethod))->toArray()
                    : null,
                'last_payment_order' => $lastOrder instanceof PaymentOrder
                    ? (new PaymentOrderResponse($lastOrder))->toArray()
                    : null,
            ],
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/subscription/renewal/retry",
     *     summary="Immediately retry a failed subscription renewal",
     *     tags={"Subscriptions"},
     *     security={{"sanctum":{"payments:create"}}},
     *
     *     @OA\Response(response=200, description="Subscription renewed"),
     *     @OA\Response(response=202, description="Renewal payment pending"),
     *     @OA\Response(response=402, description="Renewal payment declined"),
     *     @OA\Response(response=422, description="Payment recovery is unavailable or a payment method is required")
     * )
     */
    public function retryRenewal(
        Request $request,
        SubscriptionBillingService $billing,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        try {
            $result = $billing->retryFailedRenewal($user);
            $order = $result['order'];
            $status = $order?->status;
            $httpStatus = match ($status) {
                PaymentOrderStatus::Approved => 200,
                PaymentOrderStatus::Pending => 202,
                default => 402,
            };

            return response()->json([
                'message' => match ($status) {
                    PaymentOrderStatus::Approved => 'Subscription renewed successfully.',
                    PaymentOrderStatus::Pending => 'Subscription renewal is pending.',
                    default => 'Subscription renewal payment was not approved.',
                },
                'code' => match ($status) {
                    PaymentOrderStatus::Approved => 'SUBSCRIPTION_RENEWED',
                    PaymentOrderStatus::Pending => 'SUBSCRIPTION_RENEWAL_PENDING',
                    default => 'SUBSCRIPTION_RENEWAL_PAYMENT_FAILED',
                },
                'data' => [
                    'outcome' => $result['outcome'],
                    'payment_order' => $order instanceof PaymentOrder
                        ? (new PaymentOrderResponse($order))->toArray()
                        : null,
                    'subscription' => $result['subscription'] instanceof Subscription
                        ? $this->subscriptionPayload($result['subscription'])
                        : $this->subscriptionPayload($result['source_subscription']),
                ],
            ], $httpStatus);
        } catch (SubscriptionPaymentRecoveryException $exception) {
            Log::warning('Manual subscription payment retry rejected.', [
                'code' => $exception->errorCode(),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ], $exception->statusCode());
        } catch (Throwable $exception) {
            Log::error('Manual subscription payment retry failed.', [
                'exception' => $exception::class,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'message' => 'Unable to retry the subscription renewal.',
            ], 500);
        }
    }

    /**
     * @param  callable(User):Subscription  $action
     */
    private function subscriptionAction(Request $request, callable $action, string $message): JsonResponse
    {
        try {
            $user = $request->user();

            if (! $user instanceof User) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            $subscription = $action($user);

            return response()->json([
                'message' => $message,
                'data' => [
                    'subscription' => $this->subscriptionPayload($subscription),
                ],
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionPayload(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'plan' => $subscription->plan->value,
            'status' => $subscription->status->value,
            'active' => (bool) $subscription->active,
            'billing_mode' => $subscription->billing_mode,
            'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
            'cancelled_at' => $subscription->cancelled_at?->toJSON(),
            'started_at' => $subscription->started_at?->toJSON(),
            'renews_at' => $subscription->renews_at?->toJSON(),
            'next_billing_at' => $subscription->next_billing_at?->toJSON(),
            'trial_started_at' => $subscription->trial_started_at?->toJSON(),
            'trial_ends_at' => $subscription->trial_ends_at?->toJSON(),
            'trial_cancelled_at' => $subscription->trial_cancelled_at?->toJSON(),
            'trial_converted_at' => $subscription->trial_converted_at?->toJSON(),
            'payment_failure_code' => $subscription->payment_failure_code,
            'payment_failed_at' => $subscription->payment_failed_at?->toJSON(),
            'payment_retry_count' => (int) $subscription->payment_retry_count,
            'next_payment_retry_at' => $subscription->next_payment_retry_at?->toJSON(),
            'access_ended_reason' => $subscription->access_ended_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $sourceData
     */
    private function paymentSourceCreateRequest(User $user, array $sourceData): PaymentSourceCreateRequest
    {
        return new PaymentSourceCreateRequest(
            customerEmail: $user->email,
            type: (string) $sourceData['type'],
            token: (string) $sourceData['token'],
            acceptanceToken: (string) $sourceData['acceptance_token'],
            acceptPersonalAuth: (string) $sourceData['accept_personal_auth'],
            sessionId: isset($sourceData['session_id']) ? (string) $sourceData['session_id'] : null,
            customerData: $this->arrayValue($sourceData['customer_data'] ?? []),
            metadata: $this->arrayValue($sourceData['metadata'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentSourcePayload(PaymentSource $paymentSource): array
    {
        return (new PaymentMethodResponse($paymentSource))->toArray();
    }
}
