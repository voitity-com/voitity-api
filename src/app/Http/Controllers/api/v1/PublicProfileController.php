<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\PublicProfiles\PublicProfileAccess;
use App\Classes\Repositories\AvatarRepository;
use App\Classes\Subscriptions\ProfileMessagingCapabilitiesService;
use App\Http\Controllers\Controller;
use App\Http\Responses\Profile\PublicProfileResponse;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;

class PublicProfileController extends Controller
{
    public function show(
        string $alias,
        PublicProfileAccess $access,
    ): JsonResponse {
        $profile = $access->findByAlias($alias);

        if (! $profile) {
            return response()->json(['message' => 'Profile not found.'], 404);
        }

        return response()->json([
            'message' => 'Profile retrieved successfully.',
            'data' => (new PublicProfileResponse($profile))->toArray(),
        ]);
    }

    public function socialNetworks(): JsonResponse
    {
        return response()->json([
            'message' => 'Social networks retrieved successfully.',
            'data' => [
                'networks' => config('social-networks.networks', []),
            ],
        ]);
    }

    public function avatar(
        Profile $profile,
        PublicProfileAccess $access,
        AvatarRepository $avatars,
    ): JsonResponse {
        if (! $access->isVisible($profile)) {
            return response()->json(['message' => 'Profile not found.'], 404);
        }

        $avatar = $avatars->getActiveAvatarForProfile($profile);

        if (! $avatar || ! filled($avatar->file)) {
            return response()->json(['message' => 'Avatar not found.'], 404);
        }

        return response()->json([
            'message' => 'Avatar retrieved successfully.',
            'data' => [
                'file' => $avatar->file,
            ],
        ]);
    }

    public function messagingCapabilities(
        Profile $profile,
        PublicProfileAccess $access,
        ProfileMessagingCapabilitiesService $capabilities,
    ): JsonResponse {
        if (! $access->isVisible($profile)) {
            return response()->json(['message' => 'Profile not found.'], 404);
        }

        return response()->json([
            'message' => 'Profile messaging capabilities retrieved successfully.',
            'data' => $capabilities->forProfile($profile),
        ]);
    }
}
