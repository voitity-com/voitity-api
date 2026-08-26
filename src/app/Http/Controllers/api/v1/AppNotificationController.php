<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\MarkSelectedAppNotificationsAsReadRequest;
use App\Models\AppNotification;
use App\Models\User;
use App\Services\Notifications\NotificationMessageFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AppNotificationController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 50;

    private const NEW_CHAT_NOTIFICATION_KEY = 'new_chat_received';

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
        $page = max((int) $request->query('page', 1), 1);
        $groupChats = $request->boolean('group_chats');
        $timezone = $this->timezoneFor($request);

        $query = $this->filteredNotificationsQuery($user, $scope, $kind, $read);

        if ($groupChats) {
            [$notifications, $paginator] = $this->paginatedNotificationFeed(
                $query,
                $perPage,
                $page,
                $locale,
                $timezone,
            );
        } else {
            $paginator = (clone $query)->with('user')->latest()->paginate($perPage, ['*'], 'page', $page);
            $notifications = collect($paginator->items())
                ->map(fn (AppNotification $notification): array => $this->notificationToArray($notification, $locale))
                ->values()
                ->all();
        }

        return response()->json([
            'message' => 'Notifications retrieved successfully.',
            'data' => [
                'notifications' => $notifications,
                'unread_count' => $this->bellUnreadCount($user),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
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

    public function markSelectedAsRead(MarkSelectedAppNotificationsAsReadRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $now = now();
        $markedReadCount = $user->appNotifications()
            ->whereIn('id', $request->validated('notification_ids'))
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->update(['read_at' => $now, 'updated_at' => $now]);

        return response()->json([
            'message' => 'Selected notifications marked as read successfully.',
            'data' => [
                'marked_read_count' => $markedReadCount,
            ],
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
            'type' => 'notification',
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

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: LengthAwarePaginator}
     */
    private function paginatedNotificationFeed(
        Builder $query,
        int $perPage,
        int $page,
        string $locale,
        string $timezone,
    ): array {
        $driver = $query->getModel()->getConnection()->getDriverName();
        $profileKeyExpression = $this->profileKeyExpression($driver);
        $groupDateExpression = $this->groupDateExpression($driver, $timezone);
        $groupKeyExpression = "'".self::NEW_CHAT_NOTIFICATION_KEY.":' || {$profileKeyExpression} || ':' || {$groupDateExpression}";

        $individualQuery = (clone $query)
            ->where('notification_key', '!=', self::NEW_CHAT_NOTIFICATION_KEY)
            ->selectRaw("'notification' as feed_type")
            ->selectRaw('CAST(app_notifications.id AS TEXT) as feed_key')
            ->selectRaw('app_notifications.id as notification_id')
            ->selectRaw('NULL as profile_key')
            ->selectRaw('NULL as group_date')
            ->selectRaw('app_notifications.created_at as latest_at');

        $groupQuery = (clone $query)
            ->where('notification_key', self::NEW_CHAT_NOTIFICATION_KEY)
            ->selectRaw("'group' as feed_type")
            ->selectRaw("{$groupKeyExpression} as feed_key")
            ->selectRaw('NULL as notification_id')
            ->selectRaw("{$profileKeyExpression} as profile_key")
            ->selectRaw("{$groupDateExpression} as group_date")
            ->selectRaw('MAX(app_notifications.created_at) as latest_at')
            ->groupByRaw("{$profileKeyExpression}, {$groupDateExpression}");

        $feedQuery = $query->getModel()->getConnection()->query()
            ->fromSub($individualQuery->toBase()->unionAll($groupQuery->toBase()), 'notification_feed')
            ->orderByDesc('latest_at')
            ->orderByDesc('feed_key');

        $paginator = $feedQuery->paginate($perPage, ['*'], 'page', $page);
        $feedRows = collect($paginator->items());
        $individualIds = $feedRows
            ->where('feed_type', 'notification')
            ->pluck('notification_id')
            ->filter()
            ->values();
        $groupRows = $feedRows->where('feed_type', 'group')->values();

        $individualNotifications = (clone $query)
            ->with('user')
            ->whereIn('app_notifications.id', $individualIds)
            ->get()
            ->keyBy(fn (AppNotification $notification): string => (string) $notification->id);
        $groupedNotifications = $this->notificationsForGroups(
            query: $query,
            groupRows: $groupRows,
            groupDateExpression: $groupDateExpression,
            profileKeyExpression: $profileKeyExpression,
            timezone: $timezone,
        );

        $items = $feedRows
            ->map(function (object $row) use ($groupedNotifications, $individualNotifications, $locale): ?array {
                if ($row->feed_type === 'notification') {
                    $notification = $individualNotifications->get((string) $row->notification_id);

                    return $notification instanceof AppNotification
                        ? $this->notificationToArray($notification, $locale)
                        : null;
                }

                $notifications = $groupedNotifications->get((string) $row->feed_key, collect());

                return $notifications->isNotEmpty()
                    ? $this->chatNotificationGroupToArray((string) $row->feed_key, $notifications, $locale)
                    : null;
            })
            ->filter()
            ->values()
            ->all();

        return [$items, $paginator];
    }

    /**
     * @param  Collection<int, object>  $groupRows
     * @return Collection<string, Collection<int, AppNotification>>
     */
    private function notificationsForGroups(
        Builder $query,
        Collection $groupRows,
        string $groupDateExpression,
        string $profileKeyExpression,
        string $timezone,
    ): Collection {
        if ($groupRows->isEmpty()) {
            return collect();
        }

        return (clone $query)
            ->with('user')
            ->where('notification_key', self::NEW_CHAT_NOTIFICATION_KEY)
            ->where(function (Builder $query) use ($groupDateExpression, $groupRows, $profileKeyExpression): void {
                foreach ($groupRows as $row) {
                    $query->orWhere(function (Builder $query) use ($groupDateExpression, $profileKeyExpression, $row): void {
                        $query
                            ->whereRaw("{$groupDateExpression} = ?", [(string) $row->group_date])
                            ->whereRaw("{$profileKeyExpression} = ?", [(string) $row->profile_key]);
                    });
                }
            })
            ->latest('app_notifications.created_at')
            ->get()
            ->groupBy(
                fn (AppNotification $notification): string => $this->chatNotificationGroupKey($notification, $timezone)
            );
    }

    /**
     * @param  Collection<int, AppNotification>  $notifications
     * @return array<string, mixed>
     */
    private function chatNotificationGroupToArray(string $groupKey, Collection $notifications, string $locale): array
    {
        $latest = $notifications->first();
        $data = is_array($latest->data) ? $latest->data : [];
        $profileId = $data['profile_id'] ?? null;
        $profileName = $data['profile'] ?? null;
        $actionUrl = $profileId
            ? $this->formatter->format(
                self::NEW_CHAT_NOTIFICATION_KEY,
                $latest->user,
                ['profile' => $profileName, 'action_url' => "/dashboard/profiles/{$profileId}/chats"],
                $locale,
            )->actionUrl
            : $this->formatter->formatAppNotification($latest, $locale)->actionUrl;

        return [
            'type' => 'group',
            'id' => $groupKey,
            'key' => self::NEW_CHAT_NOTIFICATION_KEY,
            'category' => 'chat',
            'kind' => 'notification',
            'visible_in_bell' => $notifications->contains(
                fn (AppNotification $notification): bool => (bool) $notification->visible_in_bell
            ),
            'profile_id' => $profileId,
            'profile_name' => is_string($profileName) ? $profileName : null,
            'count' => $notifications->count(),
            'unread_count' => $notifications->whereNull('read_at')->count(),
            'notification_ids' => $notifications->pluck('id')->values()->all(),
            'notifications' => $notifications
                ->map(fn (AppNotification $notification): array => $this->notificationToArray($notification, $locale))
                ->values()
                ->all(),
            'action_url' => $actionUrl,
            'created_at' => $latest->created_at?->toJSON(),
        ];
    }

    private function chatNotificationGroupKey(AppNotification $notification, string $timezone): string
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $profileId = $data['profile_id'] ?? 'notification-'.$notification->id;
        $date = $notification->created_at?->copy()->setTimezone($timezone)->format('Y-m-d') ?? 'unknown';

        return self::NEW_CHAT_NOTIFICATION_KEY.":{$profileId}:{$date}";
    }

    private function profileKeyExpression(string $driver): string
    {
        return match ($driver) {
            'pgsql' => "COALESCE(app_notifications.data->>'profile_id', 'notification-' || CAST(app_notifications.id AS TEXT))",
            'sqlite' => "COALESCE(CAST(json_extract(app_notifications.data, '$.profile_id') AS TEXT), 'notification-' || CAST(app_notifications.id AS TEXT))",
            default => throw new \RuntimeException("Unsupported notifications database driver: {$driver}"),
        };
    }

    private function groupDateExpression(string $driver, string $timezone): string
    {
        $escapedTimezone = str_replace("'", "''", $timezone);

        return match ($driver) {
            'pgsql' => "CAST(DATE((app_notifications.created_at AT TIME ZONE 'UTC') AT TIME ZONE '{$escapedTimezone}') AS TEXT)",
            'sqlite' => 'CAST(DATE(app_notifications.created_at) AS TEXT)',
            default => throw new \RuntimeException("Unsupported notifications database driver: {$driver}"),
        };
    }

    private function timezoneFor(Request $request): string
    {
        $timezone = (string) $request->query('timezone', 'UTC');

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    private function localeFor(Request $request, User $user): string
    {
        return (string) $request->query('locale', $request->header('X-Locale', $user->locale ?: 'en'));
    }

    private function filteredNotificationsQuery(User $user, string $scope, string $kind, string $read): Builder
    {
        return AppNotification::query()
            ->where('user_id', $user->id)
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
