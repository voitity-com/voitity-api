<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\ProfilePublication\ProfileActivationService;
use App\Classes\ProfilePublication\ProfilePublicationReadinessService;
use App\Classes\Subscriptions\SubscriptionEntitlementService;
use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Enums\ProfileStatus;
use App\Enums\SubscriptionUsageType;
use App\Exceptions\Subscriptions\SubscriptionEntitlementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreProfileDataRequest;
use App\Http\Requests\Profile\StoreProfileRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Responses\Profile\ProfileListResponse;
use App\Http\Responses\Profile\ProfileResponse;
use App\Models\Profile;
use App\Models\User;
use App\Models\Voice;
use App\Services\Features\FeatureService;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProfileController extends Controller
{
    private const DEFAULT_PROFILE_LOCALE = 'es';

    private const VOICE_RESPONSE_COLUMNS = 'voices:id,profile_id,user_id,name,description,language_code,source_voice_id,source,active';

    private const AVATAR_RESPONSE_COLUMNS = 'avatars:id,profile_id,file,status';

    private const SOURCE_RESPONSE_COLUMNS = 'sources:id,profile_id,status,approved_at,indexed_at';

    /**
     * @OA\Get(
     *     path="/api/profile",
     *     summary="List authenticated user profiles",
     *     tags={"Profile"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Profiles retrieved successfully.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Profiles retrieved successfully."),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="total", type="integer", example=2),
     *                 @OA\Property(
     *                     property="profiles",
     *                     type="array",
     *
     *                     @OA\Items(
     *                         type="object",
     *
     *                         @OA\Property(property="id", type="integer", example=1),
     *                         @OA\Property(property="user_id", type="integer", example=1),
     *                         @OA\Property(property="alias", type="string", maxLength=100, nullable=true, example="JD"),
     *                         @OA\Property(property="name", type="string", example="John Doe"),
     *                         @OA\Property(property="description", type="string", example="A short bio"),
     *                         @OA\Property(property="genre", type="string", example="male"),
     *                         @OA\Property(property="personality", type="string", example="friendly"),
     *                         @OA\Property(property="active", type="boolean", example=true),
     *                         @OA\Property(property="status", type="string", enum={"draft","ready","published","hidden"}, example="draft"),
     *                         @OA\Property(property="voice", type="boolean", example=true),
     *                         @OA\Property(property="data", type="object", nullable=true),
     *                         @OA\Property(property="created_at", type="string", format="date-time", nullable=true),
     *                         @OA\Property(property="updated_at", type="string", format="date-time", nullable=true)
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="User not found"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (! $user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            $profiles = $user->profiles()
                ->with([
                    self::VOICE_RESPONSE_COLUMNS,
                    self::AVATAR_RESPONSE_COLUMNS,
                    self::SOURCE_RESPONSE_COLUMNS,
                    'conversationMessages',
                ])
                ->orderByDesc('created_at')
                ->get();

            return response()->json([
                'message' => 'Profiles retrieved successfully.',
                'data' => (new ProfileListResponse($profiles))->toArray(),
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Error listing profiles.', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/profile",
     *     summary="Create a new profile",
     *     tags={"Profile"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name","alias","description","genre","personality"},
     *
     *             @OA\Property(property="name", type="string", maxLength=100, example="John Doe"),
     *             @OA\Property(property="alias", type="string", maxLength=100, example="JD"),
     *             @OA\Property(property="description", type="string", maxLength=500, example="A short bio"),
     *             @OA\Property(property="genre", type="string", maxLength=10, example="male"),
     *             @OA\Property(property="personality", type="string", maxLength=200, example="friendly"),
     *             @OA\Property(property="active", type="boolean", example=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Profile created successfully.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Profile created successfully."),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=402, description="Subscription limit exceeded"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(
        StoreProfileRequest $request,
        SubscriptionEntitlementService $entitlements,
        SubscriptionUsageRecorder $usageRecorder,
        FeatureService $features
    ): JsonResponse {
        try {
            $user = $request->user();

            if (! $user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            $entitlements->assertCanUse($user, ['profiles' => 1]);

            [$profile] = DB::transaction(function () use ($features, $request, $usageRecorder, $user): array {
                $profile = $user->profiles()->create(array_merge($request->validated(), [
                    'active' => false,
                    'status' => ProfileStatus::Draft,
                ]));
                $features->initializeProfileFeatures($profile, false);
                $voice = $this->createBaseVoiceForProfile($profile);

                $usageRecorder->record(
                    userId: $user->id,
                    usageType: SubscriptionUsageType::ProfileCreated,
                    amounts: ['profiles' => 1],
                    idempotencyKey: "profile-created:{$profile->id}",
                    profileId: $profile->id,
                    sourceType: Profile::class,
                    sourceId: (string) $profile->id,
                );

                $profile->setRelation('voices', collect([$voice]));

                return [$profile, $voice];
            });

            app(NotificationDispatcher::class)->sendInApp(
                $user,
                'profile_created',
                $this->profileNotificationData($profile)
            );

            return response()->json([
                'message' => 'Profile created successfully.',
                'data' => (new ProfileResponse($profile))->toArray(),
            ], 200);

        } catch (SubscriptionEntitlementException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], $e->statusCode());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    private function createBaseVoiceForProfile(Profile $profile): Voice
    {
        /** @var Voice $voice */
        $voice = $profile->voices()->create([
            'user_id' => $profile->user_id,
            'name' => $profile->name,
            'description' => $profile->description,
            'language_code' => $this->normalizeProfileLocale($profile->locale),
            'source_voice_id' => null,
            'source' => null,
            'is_verified' => false,
            'verified_at' => null,
            'active' => true,
        ]);

        return $voice;
    }

    private function normalizeProfileLocale(?string $locale): string
    {
        return in_array($locale, ['en', 'es'], true) ? $locale : self::DEFAULT_PROFILE_LOCALE;
    }

    /**
     * @OA\Get(
     *     path="/api/profile/{profile}",
     *     summary="Get a profile",
     *     tags={"Profile"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="profile",
     *         in="path",
     *         required=true,
     *         description="Profile ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Profile retrieved successfully.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Profile retrieved successfully."),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="user_id", type="integer", example=1),
     *                 @OA\Property(property="alias", type="string", maxLength=100, nullable=true, example="JD"),
     *                 @OA\Property(property="name", type="string", example="John Doe"),
     *                 @OA\Property(property="description", type="string", example="A short bio"),
     *                 @OA\Property(property="genre", type="string", example="male"),
     *                 @OA\Property(property="personality", type="string", example="friendly"),
     *                 @OA\Property(property="active", type="boolean", example=true),
     *                 @OA\Property(property="status", type="string", enum={"draft","ready","published","hidden"}, example="draft"),
     *                 @OA\Property(property="voice", type="boolean", example=true),
     *                 @OA\Property(property="data", type="object", nullable=true),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="Profile not found"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function show(Request $request, Profile $profile): JsonResponse
    {
        try {
            $user = $request->user();

            if (! $user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            if (! $profile || $profile->user_id !== $user->id) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            $this->loadProfileResponseRelations($profile);

            return response()->json([
                'message' => 'Profile retrieved successfully.',
                'data' => (new ProfileResponse($profile))->toArray(),
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Error retrieving profile.', [
                'profile_id' => $profile->id ?? null,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/profile/alias/{alias}",
     *     summary="Get a profile by alias",
     *     tags={"Profile"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="alias",
     *         in="path",
     *         required=true,
     *         description="Profile alias",
     *
     *         @OA\Schema(type="string", maxLength=100)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Profile retrieved successfully.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Profile retrieved successfully."),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="user_id", type="integer", example=1),
     *                 @OA\Property(property="alias", type="string", maxLength=100, nullable=true, example="JD"),
     *                 @OA\Property(property="name", type="string", example="John Doe"),
     *                 @OA\Property(property="description", type="string", example="A short bio"),
     *                 @OA\Property(property="genre", type="string", example="male"),
     *                 @OA\Property(property="personality", type="string", example="friendly"),
     *                 @OA\Property(property="active", type="boolean", example=true),
     *                 @OA\Property(property="status", type="string", enum={"draft","ready","published","hidden"}, example="published"),
     *                 @OA\Property(property="voice", type="boolean", example=true),
     *                 @OA\Property(property="data", type="object", nullable=true),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="Profile not found"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function getProfileByAlias(Request $request, string $alias): JsonResponse
    {
        try {
            $profile = Profile::where('alias', $alias)
                ->where('active', true)
                ->where('status', ProfileStatus::Published->value)
                ->with([
                    self::VOICE_RESPONSE_COLUMNS,
                    self::AVATAR_RESPONSE_COLUMNS,
                    self::SOURCE_RESPONSE_COLUMNS,
                    'conversationMessages',
                ])
                ->first();

            if (! $profile) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            return response()->json([
                'message' => 'Profile retrieved successfully.',
                'data' => (new ProfileResponse($profile))->toArray(),
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Error retrieving profile by alias.', [
                'alias' => $alias,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/profile/social-networks",
     *     summary="List supported profile social networks",
     *     tags={"Profile"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Social networks retrieved successfully.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Social networks retrieved successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="networks",
     *                     type="object",
     *                     example={"facebook": {"name": "Facebook", "icon": "https://bigmelo-prod-profiles-139194331469.s3.amazonaws.com/icons/facebook.png"}}
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="User not found"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function socialNetworks(Request $request): JsonResponse
    {
        try {
            if (! $request->user()) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            return response()->json([
                'message' => 'Social networks retrieved successfully.',
                'data' => [
                    'networks' => config('social-networks.networks', []),
                ],
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Error listing social networks.', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * @OA\Patch(
     *     path="/api/profile/{id}",
     *     summary="Update a profile",
     *     tags={"Profile"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Profile ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=false,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="name", type="string", maxLength=100, example="John Doe"),
     *             @OA\Property(property="alias", type="string", maxLength=100, example="JD"),
     *             @OA\Property(property="description", type="string", maxLength=500, example="A short bio"),
     *             @OA\Property(property="genre", type="string", maxLength=10, example="male"),
     *             @OA\Property(property="personality", type="string", maxLength=200, example="friendly"),
     *             @OA\Property(property="active", type="boolean", example=true),
     *             @OA\Property(property="status", type="string", enum={"draft","ready","published","hidden"}, example="published")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Profile updated successfully.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Profile updated successfully."),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="Profile not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(UpdateProfileRequest $request, Profile $profile): JsonResponse
    {
        try {
            $user = $request->user();

            if (! $user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            if (! $profile || $profile->user_id !== $user->id) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            $profile->update($request->validated());
            $this->loadProfileResponseRelations($profile);

            app(NotificationDispatcher::class)->sendInApp(
                $user,
                'profile_updated',
                $this->profileNotificationData($profile)
            );

            return response()->json([
                'message' => 'Profile updated successfully.',
                'data' => (new ProfileResponse($profile))->toArray(),
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function activate(
        Request $request,
        Profile $profile,
        ProfilePublicationReadinessService $readiness,
        ProfileActivationService $activation,
    ): JsonResponse {
        try {
            $user = $request->user();

            if (! $user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            if (! $profile || $profile->user_id !== $user->id) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            $this->loadProfileResponseRelations($profile);
            $publication = $readiness->evaluate($profile);

            if (! $publication['can_activate']) {
                $this->notifyMissingPublicationRequirements($user, $profile, $publication['missing']);

                return response()->json([
                    'message' => 'Profile cannot be activated because required information is missing.',
                    'data' => [
                        'publication' => $publication,
                    ],
                    'errors' => [
                        'publication' => $publication['missing'],
                    ],
                ], 422);
            }

            $profile = $activation->activate($user, $profile);

            $this->loadProfileResponseRelations($profile);

            app(NotificationDispatcher::class)->send(
                $user,
                'profile_activated_or_published',
                $this->profileNotificationData($profile)
            );

            return response()->json([
                'message' => 'Profile activated successfully.',
                'data' => (new ProfileResponse($profile))->toArray(),
            ], 200);
        } catch (SubscriptionEntitlementException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], $e->statusCode());
        } catch (\Throwable $e) {
            Log::error('Error activating profile.', [
                'profile_id' => $profile->id ?? null,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function deactivate(Request $request, Profile $profile): JsonResponse
    {
        try {
            $user = $request->user();

            if (! $user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            if (! $profile || $profile->user_id !== $user->id) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            $profile->forceFill([
                'active' => false,
                'status' => ProfileStatus::Hidden,
            ])->save();

            $this->loadProfileResponseRelations($profile);

            app(NotificationDispatcher::class)->send(
                $user,
                'profile_deactivated',
                $this->profileNotificationData($profile)
            );

            return response()->json([
                'message' => 'Profile deactivated successfully.',
                'data' => (new ProfileResponse($profile))->toArray(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Error deactivating profile.', [
                'profile_id' => $profile->id ?? null,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/profile/{profile}/data",
     *     summary="Update the data field of a profile",
     *     tags={"Profile"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="profile",
     *         in="path",
     *         required=true,
     *         description="Profile ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"data"},
     *
     *             @OA\Property(property="data", type="object", example={"me": {"bio": "text"}, "work": {"company": "Acme"}})
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Profile updated successfully.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Profile updated successfully."),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="Profile not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     *
     * @OA\Put(
     *     path="/api/profile/{profile}/data/networks",
     *     summary="Update profile social networks",
     *     tags={"Profile"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="profile",
     *         in="path",
     *         required=true,
     *         description="Profile ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"networks"},
     *
     *             @OA\Property(
     *                 property="networks",
     *                 type="object",
     *                 example={"facebook": "https://facebook.com/voitity", "instagram": "https://instagram.com/voitity"},
     *                 description="Map of supported social network key to profile URL. Sending an empty object removes all networks."
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Profile updated successfully.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Profile updated successfully."),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="Profile not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function updateData(StoreProfileDataRequest $request, Profile $profile): JsonResponse
    {
        try {
            $user = $request->user();

            if (! $user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            if (! $profile || $profile->user_id !== $user->id) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            if ($request->isUpdatingNetworks()) {
                $profile->networks = (object) $request->validated('networks');
                $profile->save();
            } else {
                $profile->update($request->validated());
            }

            $this->loadProfileResponseRelations($profile);

            app(NotificationDispatcher::class)->sendInApp(
                $user,
                'profile_updated',
                $this->profileNotificationData($profile)
            );

            return response()->json([
                'message' => 'Profile updated successfully.',
                'data' => (new ProfileResponse($profile))->toArray(),
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    private function loadProfileResponseRelations(Profile $profile): void
    {
        $profile->loadMissing([
            self::VOICE_RESPONSE_COLUMNS,
            self::AVATAR_RESPONSE_COLUMNS,
            self::SOURCE_RESPONSE_COLUMNS,
            'conversationMessages',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function profileNotificationData(Profile $profile): array
    {
        return [
            'profile' => $profile->name ?: ($profile->alias ?: "Profile {$profile->id}"),
            'profile_id' => $profile->id,
            'action_url' => "/dashboard/profiles/{$profile->id}/profile",
        ];
    }

    /**
     * @param  array<int, string>  $missing
     */
    private function notifyMissingPublicationRequirements(User $user, Profile $profile, array $missing): void
    {
        $dispatcher = app(NotificationDispatcher::class);
        $baseData = $this->profileNotificationData($profile);
        $requirements = implode(', ', $missing);

        $dispatcher->sendInApp($user, 'profile_activation_requirements_missing', [
            ...$baseData,
            'requirements' => $requirements,
        ]);
    }
}
