<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\UsdCopRateService\UsdCopRateService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class UsdCopRateController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/payments/usd-cop-rate",
     *     summary="Get the current USD to COP exchange rate",
     *     tags={"Payments"},
     *     security={{"sanctum":{"payments:read"}}},
     *
     *     @OA\Response(response=200, description="USD to COP exchange rate retrieved successfully")
     * )
     */
    public function show(UsdCopRateService $usdCopRateService): JsonResponse
    {
        $rate = $usdCopRateService->syncConfig();

        return response()->json([
            'message' => 'USD to COP exchange rate retrieved successfully.',
            'data' => $usdCopRateService->responseData($rate),
        ]);
    }
}
