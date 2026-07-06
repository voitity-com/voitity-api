<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\ChatAIService\AudioTranscriptionService;
use App\Classes\ChatAIService\ChatAITextFromAudio;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\TranscribeProfileAudioRequest;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

class ProfileAudioTranscriptionController extends Controller
{
    private const FIELD_LIMITS = [
        'description' => ['max' => 500, 'min' => 1],
        'personality' => ['max' => 200, 'min' => 1],
    ];

    public function store(
        TranscribeProfileAudioRequest $request,
        Profile $profile,
        AudioTranscriptionService $transcriptionService
    ): JsonResponse {
        try {
            $user = $request->user();

            if (! $user instanceof User) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            if (! $this->userCanTranscribeProfile($user, $profile)) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            $audio = $request->validated('audio');

            if (! $audio instanceof UploadedFile) {
                return response()->json(['message' => 'The audio field is required.'], 422);
            }

            $transcription = $transcriptionService->transcribe($audio);

            if ($transcription->isFailed()) {
                return response()->json([
                    'message' => 'Audio transcription failed.',
                    'data' => $this->failurePayload($transcription),
                ], 502);
            }

            $text = trim($transcription->text);

            if ($text === '') {
                return response()->json([
                    'message' => 'Audio transcription did not produce text.',
                    'data' => $this->failurePayload($transcription),
                ], 422);
            }

            return response()->json([
                'message' => 'Audio transcribed successfully.',
                'data' => $this->successPayload($request, $transcription, $text),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    private function userCanTranscribeProfile(User $user, Profile $profile): bool
    {
        return $user->role === 'admin' || (int) $profile->user_id === (int) $user->id;
    }

    private function successPayload(
        TranscribeProfileAudioRequest $request,
        ChatAITextFromAudio $transcription,
        string $text
    ): array {
        $field = $request->validated('field');
        $limits = is_string($field) ? (self::FIELD_LIMITS[$field] ?? null) : null;
        $characters = mb_strlen($text);

        return [
            'text' => $text,
            'source' => $transcription->source,
            'status' => $transcription->status,
            'confidence' => $transcription->confidence,
            'detected_language' => $transcription->detectedLanguage,
            'duration' => $transcription->duration,
            'word_count' => $transcription->getWordCount(),
            'characters' => $characters,
            'field' => $field,
            'limits' => $limits,
            'exceeds_limit' => $limits !== null && $characters > $limits['max'],
            'below_minimum' => $limits !== null && $characters < $limits['min'],
        ];
    }

    private function failurePayload(ChatAITextFromAudio $transcription): array
    {
        return [
            'source' => $transcription->source,
            'status' => $transcription->status,
        ];
    }
}
