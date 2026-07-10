<?php

namespace App\Services\Notifications;

use App\Models\AppNotification;
use App\Models\User;

class NotificationMessageFormatter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function format(string $key, ?User $user = null, array $data = [], ?string $locale = null): NotificationMessage
    {
        $config = $this->notificationConfig($key);
        $locale = $this->locale($locale ?? $user?->locale);
        $copy = $config['copy'][$locale] ?? $config['copy']['en'] ?? [];
        $actionUrl = $data['action_url'] ?? $config['action_url'] ?? null;

        return new NotificationMessage(
            subject: $this->interpolate((string) ($copy['subject'] ?? $copy['title'] ?? $key), $data),
            title: $this->interpolate((string) ($copy['title'] ?? $key), $data),
            body: $this->interpolate((string) ($copy['body'] ?? ''), $data),
            actionLabel: isset($copy['action']) ? $this->interpolate((string) $copy['action'], $data) : null,
            actionUrl: is_string($actionUrl) && $actionUrl !== '' ? $this->absoluteUrl($actionUrl) : null,
        );
    }

    public function formatAppNotification(AppNotification $notification, ?string $locale = null): NotificationMessage
    {
        return $this->format(
            key: $notification->notification_key,
            user: $notification->user,
            data: $notification->data ?? [],
            locale: $locale
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function notificationConfig(string $key): array
    {
        $config = config("notifications.types.{$key}", []);

        return is_array($config) ? $config : [];
    }

    private function locale(?string $locale): string
    {
        return str_starts_with((string) $locale, 'es') ? 'es' : 'en';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function interpolate(string $text, array $data): string
    {
        $replacements = [];

        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $replacements[':'.$key] = (string) $value;
            }
        }

        return strtr($text, $replacements);
    }

    private function absoluteUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $baseUrl = rtrim((string) (config('mail.branding.admin_url') ?: config('mail.branding.home_url') ?: config('app.url')), '/');

        return $baseUrl.'/'.ltrim($url, '/');
    }
}
