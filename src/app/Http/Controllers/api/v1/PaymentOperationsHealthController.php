<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\PaymentService\PaymentOperationsMonitor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PaymentOperationsHealthController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/health/payments",
     *     summary="Check payment scheduler and queue operations",
     *     tags={"Payments"},
     *
     *     @OA\Response(response=200, description="Payment operations are healthy"),
     *     @OA\Response(response=503, description="Payment scheduler or queue heartbeat is stale")
     * )
     */
    public function __invoke(PaymentOperationsMonitor $monitor): JsonResponse
    {
        $status = $monitor->status();

        if (! $status['healthy']) {
            Log::warning('Payment operations health check failed.', [
                'queue_healthy' => $status['queue']['healthy'],
                'scheduler_healthy' => $status['scheduler']['healthy'],
            ]);
        }

        return response()->json(
            ['data' => $status],
            $status['healthy'] ? 200 : 503,
        );
    }
}
