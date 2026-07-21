<?php

namespace App\Mail;

use App\Models\ContactSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactSubmissionReceived extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly ContactSubmission $submission) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) config('contact.notification_subject', 'Nuevo contacto desde Bigmelo'));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.contact.submission-received',
            with: [
                'submission' => $this->submission,
            ],
        );
    }
}
