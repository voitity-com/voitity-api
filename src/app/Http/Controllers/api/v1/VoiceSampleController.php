<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\VoiceSample;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceSampleController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'voice_id' => ['required', 'integer', 'exists:voices,id'],
            'file' => ['required', 'string', 'max:255'],
            'duration' => ['required', 'integer', 'min:0'],
            'active' => ['boolean'],
        ]);

        $voiceSample = VoiceSample::create($validated);

        return response()->json([
            'message' => 'Voice sample created successfully.',
            'data' => $voiceSample
        ], 200);
    }
}
