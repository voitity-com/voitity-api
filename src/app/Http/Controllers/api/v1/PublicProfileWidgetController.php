<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\PublicProfiles\PublicProfileAccess;
use App\Http\Controllers\Controller;
use App\Http\Responses\Profile\ProfileWidgetResponse;
use App\Models\ProfileWidget;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PublicProfileWidgetController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/public/widgets/{publicKey}",
     *     tags={"Profile"},
     *     summary="Resolve the safe public configuration for an enabled profile widget",
     *
     *     @OA\Parameter(name="publicKey", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *     @OA\Response(response=200, description="Public widget configuration retrieved"),
     *     @OA\Response(response=404, description="Widget not available")
     * )
     */
    public function show(string $publicKey, PublicProfileAccess $access): JsonResponse
    {
        $widget = ProfileWidget::query()
            ->where('public_key', $publicKey)
            ->with('profile')
            ->first();

        if (! $widget) {
            Log::notice('Public profile widget was not found.', [
                'public_key_hash' => hash('sha256', $publicKey),
            ]);

            return response()->json(['message' => 'Widget not found.'], 404);
        }

        if (! $widget->enabled || ! $widget->profile || ! $access->isVisible($widget->profile)) {
            Log::info('Public profile widget is unavailable.', [
                'profile_widget_id' => $widget->id,
                'profile_id' => $widget->profile_id,
                'widget_enabled' => (bool) $widget->enabled,
                'profile_visible' => $widget->profile ? $access->isVisible($widget->profile) : false,
            ]);

            return response()->json(['message' => 'Widget not found.'], 404);
        }

        Log::debug('Public profile widget configuration retrieved.', [
            'profile_widget_id' => $widget->id,
            'profile_id' => $widget->profile_id,
        ]);

        return response()->json([
            'message' => 'Public profile widget retrieved successfully.',
            'data' => [
                'widget' => (new ProfileWidgetResponse($widget))->toPublicArray(),
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
