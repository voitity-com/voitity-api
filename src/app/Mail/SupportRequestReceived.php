<?php

namespace App\Mail;

use App\Models\SupportRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupportRequestReceived extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly SupportRequest $supportRequest) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->supportRequest->email)],
            subject: (string) config('support.notification_subject', 'Nueva solicitud de soporte en Bigmelo'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.support.request-received',
            with: [
                'supportRequest' => $this->supportRequest,
            ],
        );
    }
}
