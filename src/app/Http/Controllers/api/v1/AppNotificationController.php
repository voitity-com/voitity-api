<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\User;
use App\Services\Notifications\NotificationMessageFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppNotificationController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 50;

    public function __construct(private readonly NotificationMessageFormatter $formatter) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $perPage = min(
            max((int) $request->query('per_page', self::DEFAULT_PER_PAGE), 1),
            self::MAX_PER_PAGE
        );
        $locale = (string) $request->query('locale', $request->header('X-Locale', $user->locale ?: 'en'));
        $scope = (string) $request->query('scope', 'all');
        $kind = (string) $request->query('kind', 'all');
        $read = (string) $request->query('read', 'all');

        $query = $this->filteredNotificationsQuery($user, $scope, $kind, $read);
        $notifications = (clone $query)->latest()->paginate($perPage);

        return response()->json([
            'message' => 'Notifications retrieved successfully.',
            'data' => [
                'notifications' => collect($notifications->items())
                    ->map(fn (AppNotification $notification): array => $this->notificationToArray($notification, $locale))
                    ->values()
                    ->all(),
                'unread_count' => $this->bellUnreadCount($user),
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                ],
            ],
        ]);
    }

    public function markAsRead(Request $request, AppNotification $notification): JsonResponse
    {
        $user = $request->user();

        if (! ($user instanceof User) || (int) $notification->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        if (! $notification->read_at) {
            $notification->read_at = now();
            $notification->save();
        }

        return response()->json([
            'message' => 'Notification marked as read successfully.',
            'data' => $this->notificationToArray($notification->fresh(), $this->localeFor($request, $user)),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->appNotifications()
            ->when($request->query('scope') === 'bell', fn ($query) => $query->where('visible_in_bell', true))
            ->when(
                in_array($request->query('kind'), ['notification', 'log'], true),
                fn ($query) => $query->where('kind', $request->query('kind'))
            )
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Notifications marked as read successfully.']);
    }

    public function destroy(Request $request, AppNotification $notification): JsonResponse
    {
        $user = $request->user();

        if (! ($user instanceof User) || (int) $notification->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->dismissed_at = now();
        $notification->read_at ??= now();
        $notification->save();

        return response()->json(['message' => 'Notification dismissed successfully.']);
    }

    private function notificationToArray(AppNotification $notification, ?string $locale = null): array
    {
        $message = $this->formatter->formatAppNotification($notification, $locale);

        return [
            'id' => $notification->id,
            'key' => $notification->notification_key,
            'category' => $notification->category,
            'kind' => $notification->kind,
            'visible_in_bell' => (bool) $notification->visible_in_bell,
            'title' => $message->title,
            'body' => $message->body,
            'action_label' => $message->actionLabel,
            'action_url' => $message->actionUrl,
            'read_at' => $notification->read_at?->toJSON(),
            'dismissed_at' => $notification->dismissed_at?->toJSON(),
            'created_at' => $notification->created_at?->toJSON(),
        ];
    }

    private function localeFor(Request $request, User $user): string
    {
        return (string) $request->query('locale', $request->header('X-Locale', $user->locale ?: 'en'));
    }

    private function filteredNotificationsQuery(User $user, string $scope, string $kind, string $read)
    {
        return $user->appNotifications()
            ->whereNull('dismissed_at')
            ->when($scope === 'bell', fn ($query) => $query->where('visible_in_bell', true)->whereNull('read_at'))
            ->when(in_array($kind, ['notification', 'log'], true), fn ($query) => $query->where('kind', $kind))
            ->when($read === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->when($read === 'read', fn ($query) => $query->whereNotNull('read_at'));
    }

    private function bellUnreadCount(User $user): int
    {
        return $user->appNotifications()
            ->where('visible_in_bell', true)
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->count();
    }
}
