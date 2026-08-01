<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Insights\ProfileInsightsRequest;
use App\Models\Profile;
use App\Models\User;
use App\Services\Insights\ProfileInsightsRange;
use App\Services\Insights\ProfileInsightsReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ProfileInsightsController extends Controller
{
    /**
     * Backward-compatible Dashboard endpoint.
     *
     * @OA\Get(
     *     path="/api/profile/{profile}/insights",
     *     summary="Get the profile insights dashboard",
     *     tags={"Profile Insights"},
     *     security={{"sanctum":{"insights:read"}}},
     *
     *     @OA\Parameter(name="profile", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="from", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="to", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="group_by", in="query", @OA\Schema(type="string", enum={"day","month"})),
     *     @OA\Parameter(name="timezone", in="query", @OA\Schema(type="string", example="America/Bogota")),
     *
     *     @OA\Response(response=200, description="Dashboard retrieved successfully"),
     *     @OA\Response(response=404, description="Profile not found"),
     *     @OA\Response(response=422, description="Invalid range")
     * )
     */
    public function show(
        ProfileInsightsRequest $request,
        Profile $profile,
        ProfileInsightsReportService $reports,
    ): JsonResponse {
        return $this->report($request, $profile, 'dashboard', fn (ProfileInsightsRange $range): array => $reports->dashboard($profile, $range));
    }

    /**
     * @OA\Get(
     *     path="/api/profile/{profile}/insights/dashboard",
     *     summary="Get dashboard KPIs, trends, providers and classification coverage",
     *     tags={"Profile Insights"}, security={{"sanctum":{"insights:read"}}},
     *
     *     @OA\Parameter(name="profile", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="from", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="to", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="group_by", in="query", @OA\Schema(type="string", enum={"day","month"})),
     *     @OA\Parameter(name="timezone", in="query", @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="Dashboard retrieved successfully")
     * )
     */
    public function dashboard(
        ProfileInsightsRequest $request,
        Profile $profile,
        ProfileInsightsReportService $reports,
    ): JsonResponse {
        return $this->report($request, $profile, 'dashboard', fn (ProfileInsightsRange $range): array => $reports->dashboard($profile, $range));
    }

    /**
     * @OA\Get(
     *     path="/api/profile/{profile}/insights/chats",
     *     summary="Get conversation goals, quality and downstream actions",
     *     tags={"Profile Insights"}, security={{"sanctum":{"insights:read"}}},
     *
     *     @OA\Parameter(name="profile", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="from", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="to", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="group_by", in="query", @OA\Schema(type="string", enum={"day","month"})),
     *     @OA\Parameter(name="timezone", in="query", @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="Chat insights retrieved successfully")
     * )
     */
    public function chats(
        ProfileInsightsRequest $request,
        Profile $profile,
        ProfileInsightsReportService $reports,
    ): JsonResponse {
        return $this->report($request, $profile, 'chats', fn (ProfileInsightsRange $range): array => $reports->chats($profile, $range));
    }

    /**
     * @OA\Get(
     *     path="/api/profile/{profile}/insights/products",
     *     summary="Get active and historical product performance",
     *     tags={"Profile Insights"}, security={{"sanctum":{"insights:read"}}},
     *
     *     @OA\Parameter(name="profile", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="from", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="to", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="group_by", in="query", @OA\Schema(type="string", enum={"day","month"})),
     *     @OA\Parameter(name="timezone", in="query", @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="Product insights retrieved successfully")
     * )
     */
    public function products(
        ProfileInsightsRequest $request,
        Profile $profile,
        ProfileInsightsReportService $reports,
    ): JsonResponse {
        return $this->report($request, $profile, 'products', fn (ProfileInsightsRange $range): array => $reports->products($profile, $range));
    }

    /**
     * @param  callable(ProfileInsightsRange): array<string, mixed>  $build
     */
    private function report(
        ProfileInsightsRequest $request,
        Profile $profile,
        string $section,
        callable $build,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User || ! $this->canView($user, $profile)) {
            return response()->json(['message' => 'Profile not found.'], 404);
        }

        $range = ProfileInsightsRange::fromRequest($request);

        if ($range->exceedsMaximum()) {
            return response()->json(['message' => 'Insights range cannot exceed 24 months.'], 422);
        }

        $startedAt = hrtime(true);
        $data = $build($range);
        $durationMs = round((hrtime(true) - $startedAt) / 1_000_000, 2);

        Log::log($durationMs >= (int) config('insights.query_warn_ms', 500) ? 'warning' : 'info', 'Profile insights report generated.', [
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'section' => $section,
            'from' => $range->from->toIso8601String(),
            'to' => $range->to->toIso8601String(),
            'timezone' => $range->timezone,
            'group_by' => $range->groupBy,
            'duration_ms' => $durationMs,
            'result_rows' => $this->resultRows($data),
        ]);

        return response()->json([
            'message' => 'Profile insights retrieved successfully.',
            'data' => $data,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resultRows(array $data): int
    {
        foreach (['products', 'series', 'goals', 'categories'] as $key) {
            if (is_array($data[$key] ?? null)) {
                return count($data[$key]);
            }
        }

        return 1;
    }

    private function canView(User $user, Profile $profile): bool
    {
        return $user->role === 'admin' || (int) $profile->user_id === (int) $user->id;
    }
}
