<?php

namespace Tests\Feature;

use App\Mail\ContactSubmissionReceived;
use App\Models\ContactSubmission;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContactSubmissionTest extends TestCase
{
    #[Test]
    public function it_stores_a_public_contact_submission_and_notifies_the_configured_recipient(): void
    {
        Mail::fake();

        config([
            'contact.recipient_email' => 'sales@bigmelo.test',
            'contact.recipient_name' => 'Bigmelo Sales',
        ]);

        $response = $this
            ->withHeader('User-Agent', 'Feature Test Browser')
            ->postJson('/api/contact-submissions', $this->validPayload());

        $response
            ->assertCreated()
            ->assertJson([
                'message' => 'Contact request received successfully.',
            ])
            ->assertJsonStructure([
                'data' => ['id'],
            ]);

        $this->assertDatabaseHas('contact_submissions', [
            'name' => 'Ana Gomez',
            'email' => 'ana@example.com',
            'phone_country_code' => '+57',
            'phone_number' => '300 123 4567',
            'locale' => 'es',
            'source' => 'landing_page',
        ]);

        $submission = ContactSubmission::query()->firstOrFail();

        $this->assertNotNull($submission->consent_accepted_at);
        $this->assertNotNull($submission->notified_at);
        $this->assertSame('https://bigmelo.com/', $submission->metadata['page_url']);
        $this->assertSame('Feature Test Browser', $submission->user_agent);

        Mail::assertSent(
            ContactSubmissionReceived::class,
            fn (ContactSubmissionReceived $mail): bool => $mail->hasTo('sales@bigmelo.test')
                && $mail->submission->is($submission)
        );
    }

    #[Test]
    public function it_requires_phone_fields_and_consent(): void
    {
        Mail::fake();

        $payload = $this->validPayload([
            'phone_country_code' => '',
            'phone_number' => '',
            'consent_accepted' => false,
        ]);

        $response = $this->postJson('/api/contact-submissions', $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'phone_country_code',
                'phone_number',
                'consent_accepted',
            ]);

        $this->assertDatabaseCount('contact_submissions', 0);
        Mail::assertNothingSent();
    }

    #[Test]
    public function it_normalizes_email_locale_and_country_code_before_storing(): void
    {
        Mail::fake();

        config(['contact.recipient_email' => 'sales@bigmelo.test']);

        $response = $this->postJson('/api/contact-submissions', $this->validPayload([
            'email' => '  ANA@EXAMPLE.COM ',
            'locale' => 'fr',
            'phone_country_code' => '57',
        ]));

        $response->assertCreated();

        $this->assertDatabaseHas('contact_submissions', [
            'email' => 'ana@example.com',
            'locale' => 'en',
            'phone_country_code' => '+57',
        ]);
    }

    #[Test]
    public function it_verifies_captcha_when_enabled(): void
    {
        Mail::fake();
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true,
            ]),
        ]);

        config([
            'captcha.enabled' => true,
            'captcha.turnstile.secret_key' => 'test-secret',
            'contact.recipient_email' => 'sales@bigmelo.test',
        ]);

        $response = $this->postJson('/api/contact-submissions', $this->validPayload([
            'captcha_token' => 'valid-captcha-token',
        ]));

        $response->assertCreated();

        $this->assertDatabaseCount('contact_submissions', 1);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            && $request['secret'] === 'test-secret'
            && $request['response'] === 'valid-captcha-token');
    }

    #[Test]
    public function it_rejects_contact_submission_when_captcha_fails(): void
    {
        Mail::fake();
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => false,
                'error-codes' => ['invalid-input-response'],
            ]),
        ]);

        config([
            'captcha.enabled' => true,
            'captcha.turnstile.secret_key' => 'test-secret',
            'contact.recipient_email' => 'sales@bigmelo.test',
        ]);

        $response = $this->postJson('/api/contact-submissions', $this->validPayload([
            'captcha_token' => 'invalid-captcha-token',
        ]));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['captcha_token']);

        $this->assertDatabaseCount('contact_submissions', 0);
        Mail::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return [
            'name' => 'Ana Gomez',
            'email' => 'ana@example.com',
            'phone_country_code' => '+57',
            'phone_number' => '300 123 4567',
            'message' => 'Quiero conocer mas sobre Bigmelo para mi marca personal.',
            'locale' => 'es',
            'source' => 'landing_page',
            'consent_accepted' => true,
            'page_url' => 'https://bigmelo.com/',
            'referrer' => 'https://google.com/',
            ...$overrides,
        ];
    }
}
