<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\PublicProfiles\PublicProfileAccess;
use App\Classes\Repositories\AvatarRepository;
use App\Classes\Subscriptions\ProfileMessagingCapabilitiesService;
use App\Enums\ProfileStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Profile\PublicProfileResponse;
use App\Http\Responses\Profile\PublicProfileSeoResponse;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use Illuminate\Http\JsonResponse;

class PublicProfileController extends Controller
{
    public function seoIndex(): JsonResponse
    {
        $profiles = Profile::query()
            ->where('active', true)
            ->where('status', ProfileStatus::Published->value)
            ->with([
                'avatars' => fn ($query) => $query
                    ->where('status', ProfileAvatar::STATUS_ACTIVE)
                    ->with('aiImage')
                    ->orderByDesc('updated_at'),
            ])
            ->orderBy('alias')
            ->limit(50000)
            ->get(['id', 'alias', 'name', 'locale', 'networks', 'updated_at'])
            ->map(fn (Profile $profile): array => (new PublicProfileSeoResponse($profile))->toArray())
            ->values();

        return response()->json([
            'message' => 'Public SEO profiles retrieved successfully.',
            'data' => [
                'profiles' => $profiles,
            ],
        ])->header('Cache-Control', 'public, max-age=300');
    }

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
