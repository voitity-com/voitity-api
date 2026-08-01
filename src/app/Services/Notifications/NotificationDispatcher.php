<?php

namespace App\Services\Notifications;

use App\Mail\PlatformNotificationMail;
use App\Models\AppNotification;
use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationDispatcher
{
    private const BILLING_APP_NOTIFICATION_KEYS = [
        'failed_payment',
        'successful_subscription_renewal',
        'failed_subscription_renewal',
        'subscription_renewal_reminder',
    ];

    public function __construct(private readonly NotificationMessageFormatter $formatter) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>|null  $channels
     */
    public function send(User $user, string $key, array $data = [], ?array $channels = null): ?AppNotification
    {
        $config = $this->formatter->notificationConfig($key);

        if ($config === []) {
            return null;
        }

        $channels ??= $this->configuredChannels($config);
        $notification = null;

        if (in_array('app', $channels, true) && $this->appNotificationEnabled($key, $config)) {
            $notification = $this->createAppNotification($user, $key, $config, $data);
        }

        if (in_array('email', $channels, true) && (bool) ($config['email'] ?? false) && $this->canSendEmail($user, $key, $config)) {
            try {
                Mail::to($user->email)->send(new PlatformNotificationMail(
                    user: $user,
                    message: $this->formatter->format($key, $user, $data),
                ));
            } catch (Throwable $e) {
                Log::warning('Platform notification email could not be sent.', [
                    'user_id' => $user->id,
                    'notification_key' => $key,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $notification;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendInApp(User $user, string $key, array $data = []): ?AppNotification
    {
        return $this->send($user, $key, $data, ['app']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendEmail(User $user, string $key, array $data = []): void
    {
        $this->send($user, $key, $data, ['email']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendToAdmins(string $key, array $data = []): void
    {
        User::query()
            ->where('role', 'admin')
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($data, $key): void {
                foreach ($users as $user) {
                    if ($user instanceof User) {
                        $this->send($user, $key, $data);
                    }
                }
            });
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    private function createAppNotification(User $user, string $key, array $config, array $data): AppNotification
    {
        $kind = (string) ($config['kind'] ?? 'notification');
        $visibleInBell = array_key_exists('visible_in_bell', $config)
            ? (bool) $config['visible_in_bell']
            : $kind !== 'log';

        return AppNotification::create([
            'user_id' => $user->id,
            'notification_key' => $key,
            'category' => $config['category'] ?? null,
            'kind' => $kind,
            'visible_in_bell' => $visibleInBell,
            'data' => $data,
            'action_url' => $data['action_url'] ?? $config['action_url'] ?? null,
            'read_at' => $visibleInBell ? null : now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    private function configuredChannels(array $config): array
    {
        return array_values(array_filter([
            (bool) ($config['app'] ?? false) ? 'app' : null,
            (bool) ($config['email'] ?? false) ? 'email' : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function appNotificationEnabled(string $key, array $config): bool
    {
        if (! (bool) ($config['app'] ?? false)) {
            return false;
        }

        if (($config['category'] ?? null) !== 'billing') {
            return true;
        }

        return in_array($key, self::BILLING_APP_NOTIFICATION_KEYS, true);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function canSendEmail(User $user, string $key, array $config): bool
    {
        if (! filled($user->email)) {
            return false;
        }

        if ((bool) ($config['mandatory'] ?? false)) {
            return true;
        }

        $preferenceKey = $config['preference_key'] ?? null;

        if (! is_string($preferenceKey) || $preferenceKey === '') {
            return true;
        }

        $preferenceConfig = config("notifications.preferences.{$preferenceKey}", []);
        $defaultEnabled = (bool) ($preferenceConfig['default_enabled'] ?? false);
        $channel = (string) ($preferenceConfig['channel'] ?? 'email');

        $preference = UserNotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('notification_key', $preferenceKey)
            ->where('channel', $channel)
            ->first();

        return $preference?->enabled ?? $defaultEnabled;
    }
}
