<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\PublicProfiles\PublicProfileAccess;
use App\Enums\ProfileInsightEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Insights\StoreProfileInteractionRequest;
use App\Models\Profile;
use App\Models\ProfileIntegrationMedia;
use App\Models\ProfileProduct;
use App\Services\Insights\AnonymousVisitor;
use App\Services\Insights\ProfileInteractionRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

class PublicProfileInteractionController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/public/profiles/{profile}/interactions",
     *     summary="Record an allowlisted public profile interaction",
     *     tags={"Profile Insights"},
     *
     *     @OA\Parameter(name="profile", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"event_id","visitor_id","event_type","surface"},
     *
     *         @OA\Property(property="event_id", type="string", format="uuid"),
     *         @OA\Property(property="visitor_id", type="string", format="uuid"),
     *         @OA\Property(property="event_type", type="string", enum={"profile_viewed","profile_shared","product_clicked","media_opened","media_external_clicked","social_link_clicked"}),
     *         @OA\Property(property="chat_id", type="integer", nullable=true),
     *         @OA\Property(property="subject_id", type="string", nullable=true),
     *         @OA\Property(property="provider", type="string", nullable=true),
     *         @OA\Property(property="destination_type", type="string", nullable=true, enum={"provider_video","provider_channel"}),
     *         @OA\Property(property="surface", type="string"),
     *         @OA\Property(property="metadata", type="object",
     *             @OA\Property(property="share_method", type="string", enum={"native","clipboard"})
     *         )
     *     )),
     *
     *     @OA\Response(response=201, description="Interaction recorded"),
     *     @OA\Response(response=200, description="Duplicate interaction accepted without recounting"),
     *     @OA\Response(response=404, description="Profile not found"),
     *     @OA\Response(response=422, description="Invalid event or subject")
     * )
     */
    public function store(
        StoreProfileInteractionRequest $request,
        Profile $profile,
        PublicProfileAccess $access,
        AnonymousVisitor $visitors,
        ProfileInteractionRecorder $recorder,
    ): JsonResponse {
        if (! $access->isVisible($profile)) {
            return response()->json(['message' => 'Profile not found.'], 404);
        }

        $data = $request->validated();
        $eventType = ProfileInsightEventType::from($data['event_type']);
        $chatId = isset($data['chat_id']) ? (int) $data['chat_id'] : null;
        $product = null;

        if ($chatId && ! $profile->chats()->whereKey($chatId)->exists()) {
            return response()->json(['message' => 'Chat not found.'], 422);
        }

        $subjectError = $this->validateSubject($profile, $eventType, $data['subject_id'] ?? null, $data['provider'] ?? null);

        if ($subjectError !== null) {
            return response()->json(['message' => $subjectError], 422);
        }

        if ($eventType === ProfileInsightEventType::ProductClicked) {
            $product = ProfileProduct::query()
                ->where('profile_id', $profile->id)
                ->whereKey($data['subject_id'])
                ->firstOrFail();
        }

        $event = $recorder->record([
            'profile_id' => $profile->id,
            'chat_id' => $chatId,
            'visitor_id_hash' => $visitors->hash($data['visitor_id']),
            'event_type' => $eventType,
            'subject_type' => $this->subjectType($eventType),
            'subject_id' => $data['subject_id'] ?? null,
            'provider' => $recorder->provider($data['provider'] ?? null),
            'surface' => $data['surface'] ?? null,
            'media_type' => $data['media_type'] ?? null,
            'destination_type' => $data['destination_type'] ?? null,
            'occurred_at' => now(),
            'metadata' => $product instanceof ProfileProduct
                ? ['destination_type' => $product->destination_type->value]
                : Arr::only((array) ($data['metadata'] ?? []), ['destination_type', 'share_method']),
            'idempotency_key' => "profile:{$profile->id}:client:{$data['event_id']}",
            ...($product instanceof ProfileProduct ? $recorder->productSnapshot($product) : []),
        ]);

        return response()->json([
            'message' => 'Profile interaction recorded.',
            'data' => ['event_id' => $event->id, 'recorded' => $event->wasRecentlyCreated],
        ], $event->wasRecentlyCreated ? 201 : 200);
    }

    private function validateSubject(Profile $profile, ProfileInsightEventType $type, ?string $subjectId, ?string $provider): ?string
    {
        if ($type === ProfileInsightEventType::ProductClicked) {
            if (! $subjectId || ! ProfileProduct::query()->where('profile_id', $profile->id)->whereKey($subjectId)->exists()) {
                return 'Product not found for profile.';
            }
        }

        if (in_array($type, [ProfileInsightEventType::MediaOpened, ProfileInsightEventType::MediaExternalClicked], true)) {
            if (! $subjectId || ! ProfileIntegrationMedia::query()->where('profile_id', $profile->id)->whereKey($subjectId)->exists()) {
                return 'Media not found for profile.';
            }
        }

        if ($type === ProfileInsightEventType::SocialLinkClicked) {
            $key = strtolower(trim((string) $provider));
            $networks = array_change_key_case((array) ($profile->networks ?? []), CASE_LOWER);

            if ($key === '' || ! array_key_exists($key, $networks)) {
                return 'Social network not found for profile.';
            }
        }

        return null;
    }

    private function subjectType(ProfileInsightEventType $type): ?string
    {
        return match ($type) {
            ProfileInsightEventType::ProductClicked => 'product',
            ProfileInsightEventType::MediaOpened, ProfileInsightEventType::MediaExternalClicked => 'media',
            ProfileInsightEventType::SocialLinkClicked => 'social_network',
            default => null,
        };
    }
}
