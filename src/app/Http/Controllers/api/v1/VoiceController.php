<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Voice\StoreVoiceRequest;
use Illuminate\Http\JsonResponse;

class VoiceController extends Controller
{
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

            $voice = $user->voices()->create($request->validated());

            return response()->json(['message' => 'Voice created successfully.', 'data' => $voice], 200);

        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
