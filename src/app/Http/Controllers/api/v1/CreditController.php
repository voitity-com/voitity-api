<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\Subscriptions\CreditAmount;
use App\Classes\Subscriptions\CreditPurchaseService;
use App\Classes\Subscriptions\CreditWalletService;
use App\Enums\PaymentProductType;
use App\Exceptions\Subscriptions\SubscriptionEntitlementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Credits\PurchaseCreditsRequest;
use App\Http\Responses\Credits\CreditWalletResponse;
use App\Http\Responses\Payments\PaymentOrderResponse;
use App\Models\PaymentOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/credits/catalog",
     *     summary="Get purchased-credit packages and usage tariffs",
     *     tags={"Credits"},
     *     security={{"sanctum":{"payments:read"}}},
     *
     *     @OA\Response(response=200, description="Credit catalog retrieved successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized")
     * )
     */
    public function catalog(): JsonResponse
    {
        $creditsConfig = config('subscriptions.credit_store', []);
        $pricePerThousand = (float) ($creditsConfig['price_per_1000_usd'] ?? 10);
        $unitsPerCredit = CreditAmount::unitsPerCredit();

        return response()->json([
            'message' => 'Credit catalog retrieved successfully.',
            'data' => [
                'enabled' => (bool) ($creditsConfig['enabled'] ?? false),
                'currency' => 'USD',
                'price_per_1000_usd' => $pricePerThousand,
                'minimum_purchase_credits' => (int) ($creditsConfig['minimum_purchase_credits'] ?? 1000),
                'maximum_purchase_credits' => (int) ($creditsConfig['maximum_purchase_credits'] ?? 100000),
                'purchase_step_credits' => (int) ($creditsConfig['purchase_step_credits'] ?? 1000),
                'packages' => collect($creditsConfig['preset_packages'] ?? [])
                    ->map(fn (int $credits): array => [
                        'credits' => $credits,
                        'price_usd' => round(($credits / 1000) * $pricePerThousand, 2),
                    ])
                    ->values()
                    ->all(),
                'tariff_version' => $creditsConfig['tariff_version'] ?? null,
                'rates' => collect($creditsConfig['rates_in_units'] ?? [])
                    ->map(fn (int $units): float => round($units / $unitsPerCredit, 3))
                    ->all(),
            ],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/credits/wallet",
     *     summary="Get the authenticated user's purchased-credit wallet",
     *     tags={"Credits"},
     *     security={{"sanctum":{"payments:read"}}},
     *
     *     @OA\Response(response=200, description="Credit wallet retrieved successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized")
     * )
     */
    public function wallet(Request $request, CreditWalletService $wallets): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json([
            'message' => 'Credit wallet retrieved successfully.',
            'data' => (new CreditWalletResponse($wallets->walletForUser($user)))->toArray(),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/credits/purchases",
     *     summary="List purchased-credit payment orders",
     *     tags={"Credits"},
     *     security={{"sanctum":{"payments:read"}}},
     *
     *     @OA\Response(response=200, description="Credit purchases retrieved successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized")
     * )
     */
    public function purchases(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $orders = PaymentOrder::query()
            ->where('user_id', $user->id)
            ->where('product_type', PaymentProductType::CreditPack)
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'message' => 'Credit purchases retrieved successfully.',
            'data' => [
                'items' => collect($orders->items())
                    ->map(fn (PaymentOrder $order): array => (new PaymentOrderResponse($order))->toArray())
                    ->all(),
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                ],
            ],
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/credits/purchases",
     *     summary="Purchase persistent credits with the subscription payment source",
     *     tags={"Credits"},
     *     security={{"sanctum":{"payments:create"}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"credits","idempotency_key","terms_accepted"},
     *
     *             @OA\Property(property="credits", type="integer", minimum=1000, maximum=100000, example=1000),
     *             @OA\Property(property="idempotency_key", type="string", maxLength=100, example="credit-purchase-uuid"),
     *             @OA\Property(property="payment_source_id", type="integer", nullable=true, example=42),
     *             @OA\Property(property="terms_accepted", type="boolean", example=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Credits purchased and granted"),
     *     @OA\Response(response=202, description="Credit purchase pending provider confirmation"),
     *     @OA\Response(response=402, description="Payment declined or subscription is not eligible"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function purchase(
        PurchaseCreditsRequest $request,
        CreditPurchaseService $purchases,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        try {
            $result = $purchases->purchase(
                $user,
                (int) $request->validated('credits'),
                (string) $request->validated('idempotency_key'),
                $request->validated('payment_source_id') !== null
                    ? (int) $request->validated('payment_source_id')
                    : null,
            );
            $status = $result['order']->status->value;
            $httpStatus = match ($status) {
                'approved' => 201,
                'pending' => 202,
                default => 402,
            };

            return response()->json([
                'message' => match ($status) {
                    'approved' => 'Credits purchased successfully.',
                    'pending' => 'Credit purchase is pending.',
                    default => 'Credit purchase was not approved.',
                },
                'code' => match ($status) {
                    'approved' => 'CREDITS_PURCHASED',
                    'pending' => 'CREDIT_PURCHASE_PENDING',
                    'declined' => 'CREDIT_PAYMENT_DECLINED',
                    default => 'CREDIT_PAYMENT_FAILED',
                },
                'data' => [
                    'payment_order' => (new PaymentOrderResponse($result['order']))->toArray(),
                    'wallet' => (new CreditWalletResponse($result['wallet']))->toArray(),
                ],
            ], $httpStatus);
        } catch (SubscriptionEntitlementException $exception) {
            return response()->json([
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->statusCode());
        }
    }
}
