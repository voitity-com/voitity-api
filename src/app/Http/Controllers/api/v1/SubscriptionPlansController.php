<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\Subscriptions\SubscriptionTrialService;
use App\Http\Controllers\Controller;
use App\Http\Responses\Subscription\SubscriptionPlansResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function index(Request $request, SubscriptionTrialService $trialService): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'message' => 'Subscription plans retrieved successfully.',
            'data' => (new SubscriptionPlansResponse(
                config('subscriptions.plans', []),
                $user instanceof User ? $user : null,
                $trialService,
            ))->toArray(),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/subscription/public-plans",
     *     summary="Get public plan prices, limits, credits, and capabilities",
     *     tags={"Subscription"},
     *
     *     @OA\Response(response=200, description="Public subscription plans retrieved successfully")
     * )
     */
    public function publicIndex(): JsonResponse
    {
        return response()->json([
            'message' => 'Public subscription plans retrieved successfully.',
            'data' => (new SubscriptionPlansResponse(
                config('subscriptions.plans', []),
            ))->toArray(),
        ]);
    }
}
