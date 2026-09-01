<?php

namespace App\Http\Controllers\api\v1;

use App\Enums\ActivationEventType;
use App\Enums\ProfileStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivationEvent;
use App\Models\Profile;
use App\Models\User;
use App\Services\Activation\ActivationEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileActivationProgressController extends Controller
{
    public function show(Request $request, Profile $profile): JsonResponse
    {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $networks = array_change_key_case((array) ($profile->networks ?? []), CASE_LOWER);

        return response()->json([
            'message' => 'Profile activation progress retrieved successfully.',
            'data' => [
                'published' => $profile->active && $profile->status === ProfileStatus::Published,
                'whatsapp_added' => filled($networks['whatsapp'] ?? null),
                'product_created' => $profile->products()->exists(),
                'conversation_started' => $profile->chats()->exists(),
                'link_copied' => ActivationEvent::query()
                    ->where('profile_id', $profile->id)
                    ->where('event_type', ActivationEventType::LinkCopied->value)
                    ->exists(),
            ],
        ]);
    }

    public function store(Request $request, Profile $profile, ActivationEventRecorder $events): JsonResponse
    {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $validated = $request->validate([
            'event_type' => ['required', Rule::in([ActivationEventType::LinkCopied->value])],
        ]);
        $user = $request->user();
        $event = $events->record(
            $user,
            ActivationEventType::from($validated['event_type']),
            "profile:{$profile->id}:link-copied",
            profile: $profile,
            metadata: ['surface' => 'admin_publication_dock'],
        );

        return response()->json([
            'message' => 'Profile activation event recorded successfully.',
            'data' => ['event_id' => $event->id, 'event_type' => $event->event_type->value],
        ], $event->wasRecentlyCreated ? 201 : 200);
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
