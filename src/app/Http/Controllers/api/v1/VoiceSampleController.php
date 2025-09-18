<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Voice\StoreVoiceSampleRequest;
use App\Models\Voice;
use App\Models\VoiceSample;
use Illuminate\Http\JsonResponse;

class VoiceSampleController extends Controller
{
    public function store(StoreVoiceSampleRequest $request, Voice $voice): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if (!$voice || $voice->user_id !== $user->id) {
            return response()->json(['message' => 'Voice not found.'], 404);
        }

        $validated = $request->validated();
        $validated['voice_id'] = $voice->id;
        
        // Handle file upload and get duration
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $validated['file'] = $fileName;
            $validated['duration'] = 120; // Default duration for now
        }

        $voiceSample = VoiceSample::create($validated);

        return response()->json([
            'message' => 'Voice sample created successfully.',
            'data' => $voiceSample
        ], 200);
    }
}
