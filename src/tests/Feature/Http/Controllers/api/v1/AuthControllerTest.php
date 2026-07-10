<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Mail\Auth\VerifyEmailAddress;
use App\Mail\Auth\WelcomeEmail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

class AuthControllerTest extends TestAPI
{
    /**
     * Auth api endpoint
     */
    const ENDPOINT_AUTH = '/api/auth';

    #[Test]
    public function get_access_token_with_email_and_password(): void
    {
        // Create the test user first
        \App\Models\User::create([
            'name' => 'Test Admin User',
            'email' => 'voitity@gmail.com',
            'password' => bcrypt('qwerty123'),
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $response = $this->json('post', self::ENDPOINT_AUTH.'/get-token', [
            'email' => 'voitity@gmail.com',
            'password' => 'qwerty123',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(200);
        $response->assertJsonStructure(['access_token']);
    }

    #[Test]
    public function login_error_wrong_credentials(): void
    {
        $response = $this->json('post', self::ENDPOINT_AUTH.'/get-token', [
            'email' => 'wrong_email@mydomain.com',
            'password' => 'wrong_password',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Your email or password are incorrect.');
    }

    #[Test]
    public function sign_up_creates_pending_email_user_and_sends_verification_email(): void
    {
        Mail::fake();

        $response = $this->postJson(self::ENDPOINT_AUTH.'/sign-up', [
            'name' => 'Abel Moreno',
            'email' => 'Abel.SignUp@example.com',
            'locale' => 'es',
            'password' => 'Test12345!',
            'password_confirmation' => 'Test12345!',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'message',
            'email_verification_required',
            'user' => [
                'id',
                'name',
                'email',
                'first_name',
                'last_name',
                'provider',
                'role',
                'locale',
                'email_verified_at',
            ],
        ]);
        $response->assertJsonPath('email_verification_required', true);
        $response->assertJsonPath('user.name', 'Abel Moreno');
        $response->assertJsonPath('user.first_name', 'Abel');
        $response->assertJsonPath('user.last_name', 'Moreno');
        $response->assertJsonPath('user.email', 'abel.signup@example.com');
        $response->assertJsonPath('user.provider', 'email');
        $response->assertJsonPath('user.role', 'user');
        $response->assertJsonPath('user.locale', 'es');
        $response->assertJsonPath('user.email_verified_at', null);

        $user = User::where('email', 'abel.signup@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('Test12345!', $user->password));
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->email_verification_token);
        $this->assertNotNull($user->email_verification_sent_at);
        $this->assertNotNull($user->email_verification_expires_at);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => 'user',
            'provider' => 'email',
            'first_name' => 'Abel',
            'last_name' => 'Moreno',
            'locale' => 'es',
        ]);

        Mail::assertSent(
            VerifyEmailAddress::class,
            fn (VerifyEmailAddress $mail): bool => $mail->hasTo('abel.signup@example.com')
                && str_contains($mail->verificationUrl, '/api/auth/verify-email/'.$user->id)
        );
        Mail::assertNotSent(WelcomeEmail::class);
    }

    #[Test]
    public function sign_up_rejects_duplicate_email(): void
    {
        User::factory()->create([
            'email' => 'existing@example.com',
            'role' => 'user',
        ]);

        $response = $this->postJson(self::ENDPOINT_AUTH.'/sign-up', [
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'password' => 'Test12345!',
            'password_confirmation' => 'Test12345!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    #[Test]
    public function sign_up_rejects_email_used_by_google_user(): void
    {
        User::factory()->create([
            'email' => 'google-user@example.com',
            'provider' => 'google',
            'google_id' => 'google-123',
        ]);

        $response = $this->postJson(self::ENDPOINT_AUTH.'/sign-up', [
            'name' => 'Google User',
            'email' => 'google-user@example.com',
            'password' => 'Test12345!',
            'password_confirmation' => 'Test12345!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    #[Test]
    public function login_is_blocked_until_email_is_verified(): void
    {
        User::factory()->unverified()->create([
            'email' => 'pending@example.com',
            'password' => 'Test12345!',
            'provider' => 'email',
        ]);

        $response = $this->postJson(self::ENDPOINT_AUTH.'/get-token', [
            'email' => 'pending@example.com',
            'password' => 'Test12345!',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Please verify your email address before signing in.');
    }

    #[Test]
    public function verification_link_confirms_account_sends_welcome_and_allows_login(): void
    {
        Mail::fake();
        config(['email-verification.redirect_url' => 'http://localhost:3000/auth/custom/sign-in']);

        $this->postJson(self::ENDPOINT_AUTH.'/sign-up', [
            'name' => 'English User',
            'email' => 'english.user@example.com',
            'locale' => 'en',
            'password' => 'Test12345!',
            'password_confirmation' => 'Test12345!',
        ])->assertStatus(201);

        $verificationUrl = null;

        Mail::assertSent(VerifyEmailAddress::class, function (VerifyEmailAddress $mail) use (&$verificationUrl): bool {
            $verificationUrl = $mail->verificationUrl;

            return $mail->hasTo('english.user@example.com');
        });

        $this->assertIsString($verificationUrl);

        $verifyResponse = $this->getJson($this->pathFromUrl($verificationUrl, true));

        $verifyResponse->assertStatus(200);
        $verifyResponse->assertJsonPath('status', 'verified');

        $user = User::where('email', 'english.user@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verification_token);

        Mail::assertSent(
            WelcomeEmail::class,
            fn (WelcomeEmail $mail): bool => $mail->hasTo('english.user@example.com')
        );

        $loginResponse = $this->postJson(self::ENDPOINT_AUTH.'/get-token', [
            'email' => 'english.user@example.com',
            'password' => 'Test12345!',
        ]);

        $loginResponse->assertStatus(200);
        $loginResponse->assertJsonStructure(['access_token']);
    }

    #[Test]
    public function verification_link_redirects_to_admin_sign_in_with_status(): void
    {
        Mail::fake();
        config(['email-verification.redirect_url' => 'http://localhost:3000/auth/custom/sign-in']);

        $this->postJson(self::ENDPOINT_AUTH.'/sign-up', [
            'name' => 'Redirect User',
            'email' => 'redirect.user@example.com',
            'password' => 'Test12345!',
            'password_confirmation' => 'Test12345!',
        ])->assertStatus(201);

        $verificationUrl = null;

        Mail::assertSent(VerifyEmailAddress::class, function (VerifyEmailAddress $mail) use (&$verificationUrl): bool {
            $verificationUrl = $mail->verificationUrl;

            return true;
        });

        $response = $this->get($this->pathFromUrl($verificationUrl));
        $response->assertRedirect('http://localhost:3000/auth/custom/sign-in?verification=verified&locale=en');
    }

    #[Test]
    public function localized_auth_emails_render_in_english_and_spanish(): void
    {
        config(['mail.branding.logo_url' => 'https://cdn.example.com/bigmelo-logo.png']);

        $englishUser = User::factory()->make(['locale' => 'en', 'name' => 'English User']);
        $spanishUser = User::factory()->make(['locale' => 'es', 'name' => 'Usuario Espanol']);

        $englishVerification = (new VerifyEmailAddress($englishUser, 'https://example.com/verify'))->render();
        $spanishVerification = (new VerifyEmailAddress($spanishUser, 'https://example.com/verificar'))->render();
        $englishWelcome = (new WelcomeEmail($englishUser))->render();
        $spanishWelcome = (new WelcomeEmail($spanishUser))->render();

        $this->assertStringContainsString('Confirm your email', $englishVerification);
        $this->assertStringContainsString('Confirma tu correo', $spanishVerification);
        $this->assertStringContainsString('Your account is confirmed', $englishWelcome);
        $this->assertStringContainsString('Tu cuenta ya está confirmada', $spanishWelcome);
        $this->assertStringContainsString('https://cdn.example.com/bigmelo-logo.png', $englishVerification);
    }

    #[Test]
    public function sign_up_validates_required_fields_and_password_confirmation(): void
    {
        $response = $this->postJson(self::ENDPOINT_AUTH.'/sign-up', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    private function pathFromUrl(string $url, bool $removeRedirect = false): string
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '/';
        $query = '';

        if (isset($parts['query'])) {
            parse_str($parts['query'], $queryParams);

            if ($removeRedirect) {
                unset($queryParams['redirect']);
            }

            $query = $queryParams ? '?'.http_build_query($queryParams) : '';
        }

        return $path.$query;
    }
}
