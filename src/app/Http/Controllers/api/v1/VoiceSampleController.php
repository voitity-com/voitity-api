<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Voice\StoreVoiceSampleRequest;
use App\Models\Voice;
use App\Models\VoiceSample;
use App\Classes\VoiceSampleFileManager;
use App\Models\VoiceProviderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceSampleController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/voice/{voice}/sample",
     *     summary="Upload a voice sample for a specific voice",
     *     tags={"Voice Sample"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="voice",
     *         in="path",
     *         required=true,
     *         description="Voice ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"file"},
     *                 @OA\Property(
     *                     property="file",
     *                     type="file",
     *                     format="binary",
     *                     description="Audio file (MP3, WAV, etc.)",
     *                     example="sample.mp3"
     *                 ),
     *                 @OA\Property(
     *                     property="active",
     *                     type="boolean",
     *                     description="Whether the sample is active",
     *                     example=true
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voice sample uploaded successfully.",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Voice sample created successfully."),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=400, description="Failed to process file"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Voice not found or user not authorized"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(StoreVoiceSampleRequest $request, Voice $voice, VoiceSampleFileManager $fileManager): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            if (!$voice || $voice->user_id !== $user->id) {
                return response()->json(['message' => 'Voice not found.'], 404);
            }

            $validated = $request->validated();
            $validated['voice_id'] = $voice->id;
            
            if ($fileManager->processSampleFile($request->file('file'))) {
                $validated['file'] = $fileManager->getFileName();
                $validated['duration'] = $fileManager->getFileDuration();
            } else {
                return response()->json(['message' => 'Failed to process file'], 400);
            }

            $voiceSample = VoiceSample::create($validated);

            return response()->json([
                'message' => 'Voice sample created successfully.',
                'data' => $voiceSample
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/voice/{voice}/sample/{voice_sample}/process",
     *     tags={"Voice Sample"},
     *     summary="Process a voice sample for voice cloning",
     *     description="Initiates the processing of a voice sample to clone or enhance a voice. This endpoint triggers the voice cloning workflow which includes audio analysis, voice profile creation, and integration with external voice providers like ElevenLabs.",
     *     operationId="processVoiceSample",
     *     security={{"sanctum": {"voice:write"}}},
     *     @OA\Parameter(
     *         name="voice",
     *         in="path",
     *         required=true,
     *         description="The ID of the voice to process the sample for",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="voice_sample",
     *         in="path",
     *         required=true,
     *         description="The ID of the voice sample to process",
     *         @OA\Schema(type="integer", example=5)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voice sample processing initiated successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Voice sample is processing successfully."),
     *             @OA\Property(
     *                 property="data", 
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=10),
     *                 @OA\Property(property="voice_id", type="integer", example=1),
     *                 @OA\Property(property="voice_sample_id", type="integer", example=5),
     *                 @OA\Property(property="source", type="string", example=""),
     *                 @OA\Property(property="request_url", type="string", example=""),
     *                 @OA\Property(property="status", type="string", example="pending"),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-09-26T15:30:00Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-09-26T15:30:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Voice sample already processed",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Voice sample was already processed.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Voice or voice sample not found, or user not authorized",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Voice not found.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Unauthenticated.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Internal server error occurred.")
     *         )
     *     )
     * )
     */
    public function process(Request $request, Voice $voice, VoiceSample $voiceSample): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            if (!$voice || $voice->user_id !== $user->id) {
                return response()->json(['message' => 'Voice not found.'], 404);
            }

            // Check if voice sample was already processed
            $existingRequest = \App\Models\VoiceProviderRequest::where('voice_id', $voice->id)
                ->where('voice_sample_id', $voiceSample->id)
                ->first();

            if ($existingRequest) {
                return response()->json(['message' => 'Voice sample was already processed.'], 400);
            }

            // Create voiceProviderRequest record 
            $voiceProviderRequest = VoiceProviderRequest::create([
                'voice_id'          => $voice->id,
                'voice_sample_id'   => $voiceSample->id,
                'source'            => '',
                'request_url'       => '',
                'status'            => VoiceProviderRequest::STATUS_PENDING
            ]);

            // Call to event or service to process the voice sample
            event(new \App\Events\Voices\VoiceSampleAdded($voice, $voiceSample));

            return response()->json([
                'message' => 'Voice sample is processing successfully.',
                'data' => $voiceProviderRequest
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
