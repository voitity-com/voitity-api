<?php

namespace App\Mail\Auth;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->copy()['subject']);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.auth.password-reset-link',
            with: [
                'copy' => $this->copy(),
                'resetUrl' => $this->resetUrl,
                'user' => $this->user,
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
                'subject' => 'Restablece tu contraseña de Bigmelo',
                'title' => 'Restablece tu contraseña',
                'intro' => 'Recibimos una solicitud para cambiar la contraseña de tu cuenta.',
                'action' => 'Cambiar contraseña',
                'outro' => 'Este enlace solo funciona una vez. Si no solicitaste este cambio, puedes ignorar este mensaje.',
                'thanks' => 'Gracias,',
            ];
        }

        return [
            'subject' => 'Reset your Bigmelo password',
            'title' => 'Reset your password',
            'intro' => 'We received a request to change the password for your account.',
            'action' => 'Change password',
            'outro' => 'This link only works once. If you did not request this change, you can ignore this message.',
            'thanks' => 'Thanks,',
        ];
    }
}
