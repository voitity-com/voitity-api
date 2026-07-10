<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TestMailConfiguration extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $recipientEmail) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Prueba local de correo bigmelo',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.system.test-mail',
            with: [
                'homeUrl' => config('mail.branding.admin_url') ?: config('mail.branding.home_url') ?: config('app.url'),
                'mailer' => config('mail.default'),
                'recipientEmail' => $this->recipientEmail,
            ],
        );
    }
}
