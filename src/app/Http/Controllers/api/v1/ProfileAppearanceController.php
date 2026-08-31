<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileAppearanceRequest;
use App\Http\Requests\Profile\UploadProfileBackgroundImageRequest;
use App\Http\Responses\Profile\ProfileAppearanceResponse;
use App\Models\Profile;
use App\Models\ProfileAppearance;
use App\Models\User;
use App\Services\ProfileAppearanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProfileAppearanceController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/profile/{profile}/appearance",
     *     tags={"Profile"},
     *     summary="Get profile template and background settings",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="profile", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Appearance retrieved"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Profile not found")
     * )
     */
    public function show(Request $request, Profile $profile, ProfileAppearanceService $appearances): JsonResponse
    {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $appearance = $appearances->ensureForProfile($profile);

        return $this->response($appearance, 'Profile appearance retrieved successfully.');
    }

    /**
     * @OA\Patch(
     *     path="/api/profile/{profile}/appearance",
     *     tags={"Profile"},
     *     summary="Update profile template or background type",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="profile", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="template_key", type="string", enum={"profile01", "profile02", "profile03", "profile04", "profile05"}, example="profile05"),
     *             @OA\Property(property="background_type", type="string", enum={"css", "image"}, example="css")
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Appearance updated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(
        UpdateProfileAppearanceRequest $request,
        Profile $profile,
        ProfileAppearanceService $appearances,
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $appearance = $appearances->ensureForProfile($profile);
        $attributes = $request->validated();

        if (($attributes['background_type'] ?? null) === ProfileAppearance::BACKGROUND_IMAGE
            && ! filled($appearance->background_image_path)) {
            throw ValidationException::withMessages([
                'background_type' => ['Upload a background image before selecting the image background.'],
            ]);
        }

        $appearance = $appearances->update($profile, $attributes);

        Log::info('Profile appearance updated.', [
            'actor_user_id' => $request->user()?->id,
            'profile_id' => $profile->id,
            'template_key' => $appearance->template_key,
            'background_type' => $appearance->background_type,
        ]);

        return $this->response($appearance, 'Profile appearance updated successfully.');
    }

    /**
     * @OA\Post(
     *     path="/api/profile/{profile}/appearance/background-image",
     *     tags={"Profile"},
     *     summary="Upload or replace the profile background image",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="profile", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *
     *             @OA\Schema(
     *                 required={"image"},
     *
     *                 @OA\Property(property="image", type="string", format="binary"),
     *                 @OA\Property(property="template_key", type="string", enum={"profile01", "profile02", "profile03", "profile04", "profile05"}, example="profile05")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Background image uploaded"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function uploadBackgroundImage(
        UploadProfileBackgroundImageRequest $request,
        Profile $profile,
        ProfileAppearanceService $appearances,
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $appearance = $appearances->replaceBackgroundImage(
            $profile,
            $request->file('image'),
            $request->validated('template_key'),
        );

        Log::info('Profile background image uploaded.', [
            'actor_user_id' => $request->user()?->id,
            'profile_id' => $profile->id,
            'storage_disk' => $appearance->background_image_disk,
            'storage_path' => $appearance->background_image_path,
        ]);

        return $this->response($appearance, 'Profile background image uploaded successfully.');
    }

    private function response(ProfileAppearance $appearance, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => [
                'appearance' => (new ProfileAppearanceResponse($appearance))->toArray(),
                'templates' => collect(config('profile-appearance.templates', []))
                    ->map(fn (array $template, string $key): array => [
                        'key' => $key,
                        'label' => $template['label'] ?? $key,
                        'background_color' => $template['background_color'] ?? '#ffffff',
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    private function authorizeProfile(Request $request, Profile $profile): ?JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->role === 'admin' || (int) $profile->user_id === (int) $user->id) {
            return null;
        }

        return response()->json(['message' => 'Profile not found.'], 404);
    }
}
