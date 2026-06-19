<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\Subscription\SubscriptionLimitsResponse;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SubscriptionLimitsController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/subscription/limits",
     *     summary="Get active subscription limits",
     *     tags={"Subscriptions"},
     *     security={{"sanctum":{"subscription-limits:read"}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Subscription limits retrieved successfully.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Subscription limits retrieved successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="subscription",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="user_id", type="integer", example=4),
     *                     @OA\Property(property="plan", type="string", example="starter"),
     *                     @OA\Property(property="plan_name", type="string", example="Starter"),
     *                     @OA\Property(property="price_usd", type="number", format="float", example=8),
     *                     @OA\Property(property="currency", type="string", example="USD"),
     *                     @OA\Property(property="interval", type="string", example="monthly"),
     *                     @OA\Property(property="status", type="string", example="first"),
     *                     @OA\Property(property="active", type="boolean", example=true),
     *                     @OA\Property(property="started_at", type="string", format="date-time"),
     *                     @OA\Property(property="renews_at", type="string", format="date-time")
     *                 ),
     *                 @OA\Property(property="limits", type="object"),
     *                 @OA\Property(property="usage", type="object")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="Active subscription not found"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (! $user instanceof User) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            $subscription = $user->subscriptions()
                ->where('active', true)
                ->with('limit')
                ->latest('started_at')
                ->first();

            if (! $subscription instanceof Subscription) {
                return response()->json(['message' => 'Active subscription not found.'], 404);
            }

            $usageBreakdown = $this->usageBreakdown($subscription);

            return response()->json([
                'message' => 'Subscription limits retrieved successfully.',
                'data' => (new SubscriptionLimitsResponse(
                    $subscription,
                    $this->usageTotals($usageBreakdown),
                    $usageBreakdown
                ))->toArray(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Error retrieving subscription limits.', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * @return array<string, int|float>
     */
    private function usageTotals(Collection $usageBreakdown): array
    {
        return [
            'credits' => round((float) $usageBreakdown->sum('credits_used'), 2),
            'profiles' => (int) $usageBreakdown->sum('profiles_used'),
            'avatar_images' => (int) $usageBreakdown->sum('avatar_images_used'),
            'avatar_video_seconds' => (int) $usageBreakdown->sum('avatar_video_seconds_used'),
            'voice_clones' => (int) $usageBreakdown->sum('voice_clones_used'),
            'tts_characters' => (int) $usageBreakdown->sum('tts_characters_used'),
            'chat_messages' => (int) $usageBreakdown->sum('chat_messages_used'),
        ];
    }

    private function usageBreakdown(Subscription $subscription): Collection
    {
        return $subscription->uses()
            ->select('usage_type')
            ->selectRaw('COUNT(*) as records_count')
            ->selectRaw('SUM(credits_used) as credits_used')
            ->selectRaw('SUM(profiles_used) as profiles_used')
            ->selectRaw('SUM(avatar_images_used) as avatar_images_used')
            ->selectRaw('SUM(avatar_video_seconds_used) as avatar_video_seconds_used')
            ->selectRaw('SUM(voice_clones_used) as voice_clones_used')
            ->selectRaw('SUM(tts_characters_used) as tts_characters_used')
            ->selectRaw('SUM(chat_messages_used) as chat_messages_used')
            ->selectRaw('MAX(used_at) as last_used_at')
            ->groupBy('usage_type')
            ->orderBy('usage_type')
            ->get();
    }
}
