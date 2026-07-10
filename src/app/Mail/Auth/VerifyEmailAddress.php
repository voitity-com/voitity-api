<?php

namespace App\Mail\Auth;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyEmailAddress extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $verificationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->copy()['subject']);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.auth.verify-email',
            with: [
                'copy' => $this->copy(),
                'user' => $this->user,
                'verificationUrl' => $this->verificationUrl,
            ],
        );
    }

    /**
     * @return array<string, string>
     */
    private function copy(): array
    {
        if ($this->user->locale === 'es') {
            return [
                'subject' => 'Confirma tu correo en Bigmelo',
                'title' => 'Confirma tu correo',
                'intro' => 'Recibimos una solicitud para crear una cuenta en Bigmelo con este correo.',
                'action' => 'Confirmar correo',
                'outro' => 'Si no creaste esta cuenta, puedes ignorar este mensaje.',
                'thanks' => 'Gracias,',
            ];
        }

        return [
            'subject' => 'Confirm your Bigmelo email',
            'title' => 'Confirm your email',
            'intro' => 'We received a request to create a Bigmelo account with this email address.',
            'action' => 'Confirm email',
            'outro' => 'If you did not create this account, you can ignore this message.',
            'thanks' => 'Thanks,',
        ];
    }
}
