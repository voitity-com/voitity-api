<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Voice\StoreVoiceSampleRequest;
use App\Models\Voice;
use App\Models\VoiceSample;
use App\Classes\VoiceSampleFileManager;
use Illuminate\Http\JsonResponse;

class VoiceSampleController extends Controller
{
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
}
