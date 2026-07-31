<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\PaymentService\PaymentSourceCreateRequest;
use App\Classes\Subscriptions\PaymentMethodService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\StorePaymentMethodRequest;
use App\Http\Responses\Payments\PaymentMethodResponse;
use App\Exceptions\Subscriptions\PaymentMethodException;
use App\Models\PaymentSource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentMethodController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/payment-methods",
     *     summary="List the authenticated user's enabled payment methods",
     *     tags={"Payment Methods"},
     *     security={{"sanctum":{"payments:read"}}},
     *
     *     @OA\Response(response=200, description="Sanitized payment methods retrieved"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Missing token ability")
     * )
     */
    public function index(Request $request, PaymentMethodService $paymentMethods): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $methods = $paymentMethods->forUser($user)
            ->map(fn (PaymentSource $source): array => (new PaymentMethodResponse($source))->toArray())
            ->values();

        Log::info('Payment methods listed.', [
            'count' => $methods->count(),
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Payment methods retrieved successfully.',
            'data' => ['payment_methods' => $methods],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/payment-methods/setup",
     *     summary="Get public Wompi card tokenization setup",
     *     tags={"Payment Methods"},
     *     security={{"sanctum":{"payments:create"}}},
     *
     *     @OA\Response(response=200, description="Public tokenization setup retrieved"),
     *     @OA\Response(response=502, description="Provider setup unavailable")
     * )
     */
    public function setup(Request $request, PaymentMethodService $paymentMethods): JsonResponse
    {
        try {
            $setup = $paymentMethods->setup();

            Log::info('Payment method setup retrieved.', [
                'provider' => $setup['source'] ?? null,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Payment method setup retrieved successfully.',
                'data' => $setup,
            ]);
        } catch (Throwable $exception) {
            Log::error('Payment method setup failed.', [
                'exception' => $exception::class,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json(['message' => 'Unable to initialize the payment method.'], 502);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/payment-methods",
     *     summary="Register a reusable Wompi payment method",
     *     tags={"Payment Methods"},
     *     security={{"sanctum":{"payments:create"}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"type","token","acceptance_token","accept_personal_auth"},
     *             @OA\Property(property="type", type="string", example="CARD"),
     *             @OA\Property(property="token", type="string"),
     *             @OA\Property(property="acceptance_token", type="string"),
     *             @OA\Property(property="accept_personal_auth", type="string"),
     *             @OA\Property(property="make_default", type="boolean", example=true)
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Payment method added"),
     *     @OA\Response(response=422, description="Validation or provider rejection"),
     *     @OA\Response(response=500, description="Payment method registration failed")
     * )
     */
    public function store(
        StorePaymentMethodRequest $request,
        PaymentMethodService $paymentMethods,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        try {
            $source = $paymentMethods->create(
                $user,
                new PaymentSourceCreateRequest(
                    customerEmail: $user->email,
                    type: (string) $validated['type'],
                    token: (string) $validated['token'],
                    acceptanceToken: (string) $validated['acceptance_token'],
                    acceptPersonalAuth: (string) $validated['accept_personal_auth'],
                    sessionId: isset($validated['session_id']) ? (string) $validated['session_id'] : null,
                    customerData: $this->arrayValue($validated['customer_data'] ?? []),
                    metadata: $this->arrayValue($validated['metadata'] ?? []),
                ),
                (bool) ($validated['make_default'] ?? false),
            );

            return response()->json([
                'message' => 'Payment method added successfully.',
                'data' => ['payment_method' => (new PaymentMethodResponse($source))->toArray()],
            ], 201);
        } catch (PaymentMethodException $exception) {
            Log::warning('Payment method creation rejected.', [
                'code' => $exception->errorCode(),
                'reason' => $exception->getMessage(),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ], $exception->statusCode());
        } catch (Throwable $exception) {
            Log::error('Payment method creation failed.', [
                'exception' => $exception::class,
                'user_id' => $user->id,
            ]);

            return response()->json(['message' => 'Unable to add the payment method.'], 500);
        }
    }

    /**
     * @OA\Patch(
     *     path="/api/payment-methods/{paymentSource}/default",
     *     summary="Select the account default payment method",
     *     tags={"Payment Methods"},
     *     security={{"sanctum":{"payments:create"}}},
     *
     *     @OA\Parameter(
     *         name="paymentSource",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(response=200, description="Default payment method updated"),
     *     @OA\Response(response=422, description="Payment method cannot be selected")
     * )
     */
    public function makeDefault(
        Request $request,
        PaymentSource $paymentSource,
        PaymentMethodService $paymentMethods,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $source = $paymentMethods->setDefault($user, $paymentSource);

            return response()->json([
                'message' => 'Default payment method updated successfully.',
                'data' => ['payment_method' => (new PaymentMethodResponse($source))->toArray()],
            ]);
        } catch (PaymentMethodException $exception) {
            Log::warning('Default payment method change rejected.', [
                'payment_source_id' => $paymentSource->id,
                'reason' => $exception->getMessage(),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ], $exception->statusCode());
        } catch (Throwable $exception) {
            Log::error('Default payment method change failed.', [
                'exception' => $exception::class,
                'payment_source_id' => $paymentSource->id,
                'user_id' => $user->id,
            ]);

            return response()->json(['message' => 'Unable to update the default payment method.'], 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/payment-methods/{paymentSource}",
     *     summary="Soft-disable a secondary payment method",
     *     tags={"Payment Methods"},
     *     security={{"sanctum":{"payments:create"}}},
     *
     *     @OA\Parameter(
     *         name="paymentSource",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(response=200, description="Payment method removed"),
     *     @OA\Response(response=422, description="Default or pending payment method cannot be removed")
     * )
     */
    public function destroy(
        Request $request,
        PaymentSource $paymentSource,
        PaymentMethodService $paymentMethods,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $paymentMethods->disable($user, $paymentSource);

            return response()->json(['message' => 'Payment method removed successfully.']);
        } catch (PaymentMethodException $exception) {
            Log::warning('Payment method removal rejected.', [
                'payment_source_id' => $paymentSource->id,
                'reason' => $exception->getMessage(),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ], $exception->statusCode());
        } catch (Throwable $exception) {
            Log::error('Payment method removal failed.', [
                'exception' => $exception::class,
                'payment_source_id' => $paymentSource->id,
                'user_id' => $user->id,
            ]);

            return response()->json(['message' => 'Unable to remove the payment method.'], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
