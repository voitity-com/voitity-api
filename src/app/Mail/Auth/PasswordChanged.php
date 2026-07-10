<?php

namespace App\Mail\Auth;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordChanged extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->copy()['subject']);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.auth.password-changed',
            with: [
                'copy' => $this->copy(),
                'homeUrl' => config('mail.branding.admin_url') ?: config('mail.branding.home_url') ?: config('app.url'),
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
                'subject' => 'Tu contraseña de Bigmelo fue actualizada',
                'title' => 'Contraseña actualizada',
                'intro' => 'Tu contraseña fue actualizada correctamente. Si no hiciste este cambio, contacta a soporte de inmediato.',
                'action' => 'Ir a Bigmelo',
                'thanks' => 'Gracias,',
            ];
        }

        return [
            'subject' => 'Your Bigmelo password was updated',
            'title' => 'Password updated',
            'intro' => 'Your password was updated successfully. If you did not make this change, contact support immediately.',
            'action' => 'Go to Bigmelo',
            'thanks' => 'Thanks,',
        ];
    }
}
