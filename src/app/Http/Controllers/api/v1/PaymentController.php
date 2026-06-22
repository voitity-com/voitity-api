<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\PaymentService\PaymentRequest;
use App\Classes\PaymentService\PaymentService;
use App\Enums\PaymentCurrency;
use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\SubscriptionPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\CreateWompiCheckoutRequest;
use App\Http\Responses\Payments\PaymentCheckoutResponse;
use App\Http\Responses\Payments\PaymentOrderResponse;
use App\Models\PaymentOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/payments/wompi/checkout",
     *     summary="Create a Wompi checkout for a subscription plan",
     *     tags={"Payments"},
     *     security={{"sanctum":{"payments:create"}}},
     *
     *     @OA\Response(response=201, description="Wompi checkout created successfully"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function createWompiCheckout(
        CreateWompiCheckoutRequest $request,
        PaymentService $paymentService
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $plan = SubscriptionPlan::from((string) $request->validated('plan'));
        $planConfig = config("subscriptions.plans.{$plan->value}", []);
        $priceUsd = $planConfig['price_usd'] ?? null;

        if (! is_numeric($priceUsd) || (float) $priceUsd <= 0) {
            return response()->json([
                'message' => 'Selected plan is not available for checkout.',
                'errors' => ['plan' => ['Selected plan is not available for checkout.']],
            ], 422);
        }

        $exchangeRate = (float) config('payment.usd_cop_rate', 4000);

        if ($exchangeRate <= 0) {
            return response()->json([
                'message' => 'Invalid USD to COP exchange rate configuration.',
            ], 500);
        }

        $displayAmountUsd = round((float) $priceUsd, 2);
        $amountInCents = (int) round($displayAmountUsd * $exchangeRate * 100);
        $amountCop = round($amountInCents / 100, 2);
        $reference = $this->uniqueReference($user->id);

        $paymentOrder = PaymentOrder::create([
            'user_id' => $user->id,
            'provider' => PaymentProvider::Wompi,
            'reference' => $reference,
            'plan' => $plan,
            'display_amount_usd' => $displayAmountUsd,
            'display_currency' => PaymentCurrency::Usd,
            'exchange_rate' => $exchangeRate,
            'amount_cop' => $amountCop,
            'amount_in_cents' => $amountInCents,
            'currency' => PaymentCurrency::Cop,
            'status' => PaymentOrderStatus::Pending,
        ]);

        $intent = $paymentService->createPayment(new PaymentRequest(
            reference: $paymentOrder->reference,
            amountInCents: $paymentOrder->amount_in_cents,
            currency: $paymentOrder->currency->value,
            redirectUrl: config('payment.redirect_url'),
            customerData: $this->customerDataFor($user),
        ));

        $paymentOrder->checkout_url = $intent->checkoutUrl;
        $paymentOrder->raw_provider_payload = $intent->toArray();
        $paymentOrder->save();

        return response()->json([
            'message' => 'Wompi checkout created successfully.',
            'data' => (new PaymentCheckoutResponse($paymentOrder, $intent))->toArray(),
        ], 201);
    }

    /**
     * @OA\Get(
     *     path="/api/payments/{paymentOrder}",
     *     summary="Get a payment order",
     *     tags={"Payments"},
     *     security={{"sanctum":{"payments:read"}}},
     *
     *     @OA\Response(response=200, description="Payment order retrieved successfully"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function show(PaymentOrder $paymentOrder): JsonResponse
    {
        /** @var User|null $user */
        $user = request()->user();

        if (! $user || ($user->role !== 'admin' && $paymentOrder->user_id !== $user->id)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        return response()->json([
            'message' => 'Payment order retrieved successfully.',
            'data' => (new PaymentOrderResponse($paymentOrder))->toArray(),
        ]);
    }

    private function uniqueReference(int $userId): string
    {
        do {
            $reference = 'VOI-'.$userId.'-'.Str::upper(Str::random(12));
        } while (PaymentOrder::where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * @return array<string, string>
     */
    private function customerDataFor(User $user): array
    {
        $data = [
            'email' => $user->email,
            'full-name' => $user->name,
        ];

        return array_filter($data, fn (?string $value): bool => filled($value));
    }
}
