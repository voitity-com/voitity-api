<?php

namespace Tests\Unit\Services\Notifications;

use App\Mail\PlatformNotificationMail;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationMessageFormatter;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationDispatcherTest extends TestCase
{
    public function test_it_creates_in_app_notification_and_sends_email_when_configured(): void
    {
        Mail::fake();

        $user = User::factory()->create(['locale' => 'en']);

        $notification = app(NotificationDispatcher::class)->send($user, 'payment_approved', [
            'plan' => 'starter',
            'amount' => 'USD 9.00',
        ]);

        $this->assertNotNull($notification);
        $this->assertDatabaseHas('app_notifications', [
            'id' => $notification->id,
            'user_id' => $user->id,
            'notification_key' => 'payment_approved',
            'category' => 'billing',
        ]);
        Mail::assertSent(PlatformNotificationMail::class, function (PlatformNotificationMail $mail) use ($user): bool {
            return $mail->hasTo($user->email)
                && $mail->message->subject === 'Your Bigmelo payment was approved';
        });
    }

    public function test_it_can_send_only_in_app_notification(): void
    {
        Mail::fake();

        $user = User::factory()->create(['locale' => 'es']);

        app(NotificationDispatcher::class)->sendInApp($user, 'profile_created', [
            'profile' => 'Abel Dev',
        ]);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $user->id,
            'notification_key' => 'profile_created',
            'category' => 'profile',
        ]);
        Mail::assertNothingSent();
    }

    public function test_notification_action_urls_point_to_admin_url(): void
    {
        config([
            'mail.branding.admin_url' => 'http://admin.example.test',
            'mail.branding.home_url' => 'http://web.example.test',
        ]);

        $formatter = app(NotificationMessageFormatter::class);
        $user = User::factory()->make(['locale' => 'en']);

        foreach (array_keys(config('notifications.types')) as $key) {
            $message = $formatter->format($key, $user, $this->notificationDataFor($key));

            if (! $message->actionUrl) {
                $this->assertNull($message->actionUrl);
                continue;
            }

            $this->assertStringStartsWith(
                'http://admin.example.test/',
                $message->actionUrl,
                "Notification [{$key}] does not point to the admin URL."
            );
            $this->assertFalse(
                str_starts_with($message->actionUrl, 'http://web.example.test/'),
                "Notification [{$key}] points to the web URL."
            );
        }
    }

    public function test_relative_runtime_action_url_points_to_admin_url(): void
    {
        config([
            'mail.branding.admin_url' => 'http://admin.example.test',
            'mail.branding.home_url' => 'http://web.example.test',
        ]);

        $message = app(NotificationMessageFormatter::class)->format('profile_created', null, [
            'profile' => 'QA Profile',
            'action_url' => '/dashboard/profiles/123/profile',
        ]);

        $this->assertSame('http://admin.example.test/dashboard/profiles/123/profile', $message->actionUrl);
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationDataFor(string $key): array
    {
        return [
            'action_url' => config("notifications.types.{$key}.action_url"),
            'amount' => 'USD 80.00',
            'email' => 'qa@example.test',
            'message' => 'QA notification message.',
            'metric' => 'profiles',
            'plan' => 'Starter',
            'profile' => 'QA Profile',
            'profile_id' => 123,
            'reason' => 'QA simulated reason.',
            'renews_at' => '2026-07-17',
            'requirements' => 'avatar, voice, source',
            'service' => 'OpenAI',
            'source' => 'QA Source.pdf',
            'user' => 'QA User',
        ];
    }
}
