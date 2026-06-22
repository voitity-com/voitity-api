<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\Subscription\SubscriptionPlansResponse;
use Illuminate\Http\JsonResponse;

class SubscriptionPlansController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/subscription/plans",
     *     summary="Get available subscription plans",
     *     tags={"Subscription"},
     *     security={{"sanctum":{"subscription-plans:read"}}},
     *
     *     @OA\Response(response=200, description="Subscription plans retrieved successfully")
     * )
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'message' => 'Subscription plans retrieved successfully.',
            'data' => (new SubscriptionPlansResponse(config('subscriptions.plans', [])))->toArray(),
        ]);
    }
}
