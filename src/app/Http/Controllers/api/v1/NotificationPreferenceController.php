<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\NotificationPreferenceUpdateRequest;
use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json([
            'message' => 'Notification preferences retrieved successfully.',
            'data' => [
                'preferences' => $this->preferencesFor($user),
            ],
        ]);
    }

    public function update(NotificationPreferenceUpdateRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        foreach ($request->validated('preferences') as $key => $enabled) {
            $config = $this->configuredPreferences()[$key];

            UserNotificationPreference::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'notification_key' => $key,
                    'channel' => $config['channel'],
                ],
                ['enabled' => (bool) $enabled],
            );
        }

        $user->load('notificationPreferences');

        return response()->json([
            'message' => 'Notification preferences updated successfully.',
            'data' => [
                'preferences' => $this->preferencesFor($user),
            ],
        ]);
    }

    /**
     * @return array<int, array{key: string, channel: string, enabled: bool, default_enabled: bool}>
     */
    private function preferencesFor(User $user): array
    {
        $user->loadMissing('notificationPreferences');

        $stored = $user->notificationPreferences
            ->keyBy(fn (UserNotificationPreference $preference): string => $preference->notification_key.'|'.$preference->channel);

        return collect($this->configuredPreferences())
            ->map(function (array $config, string $key) use ($stored): array {
                $storedPreference = $stored->get($key.'|'.$config['channel']);
                $defaultEnabled = (bool) $config['default_enabled'];

                return [
                    'key' => $key,
                    'channel' => $config['channel'],
                    'enabled' => $storedPreference?->enabled ?? $defaultEnabled,
                    'default_enabled' => $defaultEnabled,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, array{channel: string, default_enabled: bool}>
     */
    private function configuredPreferences(): array
    {
        return (array) config('notifications.preferences', []);
    }
}
