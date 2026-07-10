<?php

namespace App\Mail\Auth;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeEmail extends Mailable
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
            markdown: 'emails.auth.welcome',
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
                'subject' => 'Bienvenido a Bigmelo',
                'title' => 'Tu cuenta ya está confirmada',
                'intro' => 'Ya puedes iniciar sesión y comenzar a crear perfiles con voz, avatar y fuentes verificadas.',
                'action' => 'Ir a Bigmelo',
                'thanks' => 'Gracias,',
            ];
        }

        return [
            'subject' => 'Welcome to Bigmelo',
            'title' => 'Your account is confirmed',
            'intro' => 'You can now sign in and start creating profiles with voice, avatar, and verified sources.',
            'action' => 'Go to Bigmelo',
            'thanks' => 'Thanks,',
        ];
    }
}
