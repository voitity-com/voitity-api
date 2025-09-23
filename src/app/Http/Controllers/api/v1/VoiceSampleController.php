<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Voice\StoreVoiceSampleRequest;
use App\Models\Voice;
use App\Models\VoiceSample;
use App\Classes\VoiceSampleFileManager;
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

            return response()->json([
                'message' => 'Voice sample is processing successfully.',
                'data' => $voiceSample
            ], 200);

        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
