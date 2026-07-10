<?php

namespace Tests\Feature\Console;

use App\Mail\TestMailConfiguration;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailTestCommandTest extends TestCase
{
    #[Test]
    public function it_sends_a_branded_test_email_to_the_requested_recipient(): void
    {
        Mail::fake();
        config(['mail.default' => 'array']);

        $this->artisan('mail:test', ['email' => 'recipient@example.com'])
            ->expectsOutput('Test email sent to recipient@example.com using array mailer.')
            ->assertSuccessful();

        Mail::assertSent(
            TestMailConfiguration::class,
            fn (TestMailConfiguration $mail): bool => $mail->hasTo('recipient@example.com')
        );
    }

    #[Test]
    public function it_renders_the_test_email_with_configured_branding(): void
    {
        config([
            'mail.branding.logo_url' => 'https://cdn.example.com/bigmelo-logo.png',
            'mail.from.name' => 'bigmelo',
        ]);

        $html = (new TestMailConfiguration('recipient@example.com'))->render();

        $this->assertStringContainsString('https://cdn.example.com/bigmelo-logo.png', $html);
        $this->assertStringContainsString('Prueba local de correo', $html);
        $this->assertStringContainsString('bigmelo', $html);
    }
}
