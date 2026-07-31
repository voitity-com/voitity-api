<?php

namespace App\Jobs;

use App\Mail\SupportRequestReceived;
use App\Models\SupportRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendSupportRequestNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public SupportRequest $supportRequest) {}

    public function handle(): void
    {
        $recipientEmail = (string) config('support.recipient_email', '');

        if (! filled($recipientEmail)) {
            $this->supportRequest->forceFill([
                'notification_error' => 'SUPPORT_RECIPIENT_EMAIL is not configured.',
            ])->save();

            Log::warning('Support request notification recipient is not configured.', [
                'support_request_id' => $this->supportRequest->id,
                'user_id' => $this->supportRequest->user_id,
            ]);

            return;
        }

        try {
            Mail::to($recipientEmail, (string) config('support.recipient_name', 'Bigmelo Support'))
                ->send(new SupportRequestReceived($this->supportRequest));

            $this->supportRequest->forceFill([
                'notified_at' => now(),
                'notification_error' => null,
            ])->save();

            Log::info('Support request notification sent.', [
                'support_request_id' => $this->supportRequest->id,
                'user_id' => $this->supportRequest->user_id,
                'profile_id' => $this->supportRequest->profile_id,
            ]);
        } catch (Throwable $e) {
            $this->supportRequest->forceFill([
                'notification_error' => $e->getMessage(),
            ])->save();

            Log::warning('Support request notification could not be sent.', [
                'support_request_id' => $this->supportRequest->id,
                'user_id' => $this->supportRequest->user_id,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
