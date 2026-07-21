<?php

namespace App\Jobs;

use App\Mail\ContactSubmissionReceived;
use App\Models\ContactSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendContactSubmissionNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public ContactSubmission $submission) {}

    public function handle(): void
    {
        $recipientEmail = (string) config('contact.recipient_email', '');

        if (! filled($recipientEmail)) {
            $this->submission->forceFill([
                'notification_error' => 'CONTACT_RECIPIENT_EMAIL is not configured.',
            ])->save();

            Log::warning('Contact submission notification recipient is not configured.', [
                'contact_submission_id' => $this->submission->id,
            ]);

            return;
        }

        try {
            Mail::to($recipientEmail, (string) config('contact.recipient_name', 'Bigmelo'))
                ->send(new ContactSubmissionReceived($this->submission));

            $this->submission->forceFill([
                'notified_at' => now(),
                'notification_error' => null,
            ])->save();
        } catch (Throwable $e) {
            $this->submission->forceFill([
                'notification_error' => $e->getMessage(),
            ])->save();

            Log::warning('Contact submission notification could not be sent.', [
                'contact_submission_id' => $this->submission->id,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
