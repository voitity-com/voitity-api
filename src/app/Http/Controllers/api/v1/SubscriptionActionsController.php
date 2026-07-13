<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\PaymentService\PaymentService;
use App\Classes\Subscriptions\SubscriptionTrialService;
use App\Enums\SubscriptionPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\CreateWompiCheckoutRequest;
use App\Http\Responses\Payments\PaymentCheckoutResponse;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class SubscriptionActionsController extends Controller
{
    public function startTrial(
        CreateWompiCheckoutRequest $request,
        PaymentService $paymentService,
        SubscriptionTrialService $trialService,
    ): JsonResponse {
        try {
            /** @var User $user */
            $user = $request->user();
            $plan = SubscriptionPlan::from((string) $request->validated('plan'));
            $checkout = $trialService->startTrialCheckout($user, $plan, $paymentService);

            return response()->json([
                'message' => 'Subscription trial checkout created successfully.',
                'data' => (new PaymentCheckoutResponse($checkout['payment_order'], $checkout['intent']))->toArray(),
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
}
