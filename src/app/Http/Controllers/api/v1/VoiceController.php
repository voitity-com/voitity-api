<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\VoiceService\VoiceManager;
use App\Classes\VoiceService\VoiceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Voice\StoreVoiceRequest;
use App\Http\Requests\Voice\TestVoiceRequest;
use App\Http\Responses\Voice\VoiceTestResponse;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class VoiceController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/voice",
     *     summary="Create a new voice",
     *     tags={"Voice"},
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name", type="string", maxLength=100, example="Sample Voice"),
     *             @OA\Property(property="description", type="string", maxLength=500, example="Voice description"),
     *             @OA\Property(property="language_code", type="string", maxLength=10, example="es"),
     *             @OA\Property(property="profile_id", type="integer", example=1, description="Profile ID (optional, will use first active profile if not provided)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voice created successfully.",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Voice created successfully."),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=400, description="User already has an active voice or no active profile found."),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="User not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(StoreVoiceRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            if ($user->voices()->where('active', true)->exists()) {
                return response()->json(['message' => 'User already has an active voice.'], 400);
            }

            // Handle profile_id logic
            $validatedData = $request->validated();
            
            if (!isset($validatedData['profile_id'])) {
                // Get the first active profile for the user
                $activeProfile = $user->profiles()->where('active', true)->first();
                
                if (!$activeProfile) {
                    return response()->json(['message' => 'User has no active profile. Please create or activate a profile first.'], 400);
                }
                
                $validatedData['profile_id'] = $activeProfile->id;
            }

            $voice = $user->voices()->create($validatedData);

            return response()->json(['message' => 'Voice created successfully.', 'data' => $voice], 200);

        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/voice/test",
     *     summary="Generate test audio for a profile voice",
     *     tags={"Voice"},
     *     security={{"sanctum":{"voice:use"}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"profile_id","text"},
     *             @OA\Property(property="profile_id", type="integer", example=1),
     *             @OA\Property(property="text", type="string", maxLength=5000, example="Hola, esta es una prueba de voz.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voice audio generated successfully.",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Voice audio generated successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="voice_id", type="integer", example=1),
     *                 @OA\Property(property="profile_id", type="integer", example=1),
     *                 @OA\Property(property="text", type="string", example="Hola, esta es una prueba de voz."),
     *                 @OA\Property(property="audio_url", type="string", nullable=true, example="http://localhost:8000/storage/generated/1/audio.mp3"),
     *                 @OA\Property(property="audio_content", type="string", nullable=true, description="Base64 encoded audio content"),
     *                 @OA\Property(property="audio_format", type="string", example="mp3"),
     *                 @OA\Property(property="duration", type="number", nullable=true, example=3.4),
     *                 @OA\Property(property="status", type="string", example="completed"),
     *                 @OA\Property(property="metadata", type="object")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="Profile or voice not found"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=502, description="Voice audio generation failed"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function test(TestVoiceRequest $request, VoiceManager $voiceManager): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            $payload = $request->validated();

            $profileQuery = Profile::where('id', $payload['profile_id']);

            if ($user->role !== 'admin') {
                $profileQuery->where('user_id', $user->id);
            }

            /** @var Profile|null $profile */
            $profile = $profileQuery->first();

            if (!$profile) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            $voiceQuery = $profile->voices()->where('active', true);

            if ($user->role !== 'admin') {
                $voiceQuery->where('user_id', $user->id);
            }

            $voice = $voiceQuery->first();

            if (!$voice) {
                return response()->json(['message' => 'Voice not found.'], 404);
            }

            Log::info('Voice test audio generation started.', [
                'user_id' => $user->id,
                'profile_id' => $profile->id,
                'voice_id' => $voice->id,
                'text_length' => strlen($payload['text']),
            ]);

            $driverName = $voice->source ?: null;
            $voiceClient = $voiceManager->driver($driverName);
            $voiceService = new VoiceService($voice, $voiceClient);
            $generatedAudio = $voiceService->generateAudio($payload['text']);

            if ($generatedAudio->isFailed()) {
                Log::warning('Voice test audio generation failed.', [
                    'user_id' => $user->id,
                    'profile_id' => $profile->id,
                    'voice_id' => $voice->id,
                    'status' => $generatedAudio->status,
                    'metadata' => $generatedAudio->metadata,
                ]);

                return response()->json([
                    'message' => 'Voice audio generation failed.',
                    'data' => (new VoiceTestResponse($generatedAudio))->toArray(),
                ], 502);
            }

            Log::info('Voice test audio generation completed.', [
                'user_id' => $user->id,
                'profile_id' => $profile->id,
                'voice_id' => $voice->id,
                'status' => $generatedAudio->status,
            ]);

            return response()->json([
                'message' => 'Voice audio generated successfully.',
                'data' => (new VoiceTestResponse($generatedAudio))->toArray(),
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Error generating voice test audio.', [
                'user_id' => $request->user()?->id,
                'profile_id' => $request->input('profile_id'),
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
