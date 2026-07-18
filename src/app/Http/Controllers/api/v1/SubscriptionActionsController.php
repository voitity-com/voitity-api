<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\PaymentService\PaymentSourceCreateRequest;
use App\Classes\PaymentService\PaymentService;
use App\Classes\Subscriptions\SubscriptionPaymentSourceService;
use App\Classes\Subscriptions\SubscriptionTrialService;
use App\Enums\SubscriptionPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\StartSubscriptionPaymentSourceRequest;
use App\Http\Requests\Payments\StartSubscriptionTrialRequest;
use App\Http\Responses\Payments\PaymentOrderResponse;
use App\Models\PaymentSource;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    ): JsonResponse {
        try {
            /** @var User $user */
            $user = $request->user();
            $plan = SubscriptionPlan::from((string) $request->validated('plan'));
            /** @var array<string, mixed> $sourceData */
            $sourceData = $request->validated('payment_source');
            $result = $subscriptionPaymentSourceService->startSubscriptionWithPaymentSource(
                $user,
                $plan,
                $paymentService,
                $this->paymentSourceCreateRequest($user, $sourceData),
            );

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
    ): JsonResponse {
        try {
            /** @var User $user */
            $user = $request->user();
            $plan = SubscriptionPlan::from((string) $request->validated('plan'));
            /** @var array<string, mixed> $sourceData */
            $sourceData = $request->validated('payment_source');
            $trial = $trialService->startTrialWithPaymentSource(
                $user,
                $plan,
                $paymentService,
                $this->paymentSourceCreateRequest($user, $sourceData),
            );

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
        ];
    }

    /**
     * @param  mixed  $value
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
        return [
            'id' => $paymentSource->id,
            'provider' => $paymentSource->provider->value,
            'provider_source_id' => $paymentSource->provider_source_id,
            'type' => $paymentSource->type,
            'status' => $paymentSource->status,
            'reusable' => (bool) $paymentSource->reusable,
            'metadata' => $paymentSource->metadata,
            'verified_at' => $paymentSource->verified_at?->toJSON(),
        ];
    }
}
