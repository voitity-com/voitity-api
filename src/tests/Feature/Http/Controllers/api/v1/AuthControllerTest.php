<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Mail\Auth\PasswordChanged;
use App\Mail\Auth\PasswordResetLink;
use App\Mail\Auth\VerifyEmailAddress;
use App\Mail\Auth\WelcomeEmail;
use App\Models\AuthLoginEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        $this->assertDatabaseHas('auth_login_events', [
            'user_id' => User::where('email', 'voitity@gmail.com')->value('id'),
            'type' => 'credential',
        ]);
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
    public function forgot_password_sends_reset_email_for_email_user(): void
    {
        Mail::fake();
        config(['password-reset.redirect_url' => 'http://localhost:3000/auth/custom/reset-password']);

        $user = User::factory()->create([
            'email' => 'reset.user@example.com',
            'locale' => 'es',
            'provider' => 'email',
        ]);

        $response = $this->postJson(self::ENDPOINT_AUTH.'/password/forgot', [
            'email' => 'RESET.USER@example.com',
            'locale' => 'en',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath(
            'message',
            'Si el correo pertenece a una cuenta con contraseña, enviamos un enlace para cambiarla.'
        );

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'reset.user@example.com']);

        Mail::assertSent(
            PasswordResetLink::class,
            fn (PasswordResetLink $mail): bool => $mail->hasTo('reset.user@example.com')
                && str_contains($mail->resetUrl, '/api/auth/password/reset')
                && str_contains($mail->resetUrl, 'email=reset.user%40example.com')
                && str_contains($mail->resetUrl, 'locale=es')
                && str_contains($mail->render(), 'Restablece tu contraseña')
        );
    }

    #[Test]
    public function forgot_password_preserves_english_account_locale_even_from_spanish_page(): void
    {
        Mail::fake();
        config(['password-reset.redirect_url' => 'http://localhost:3000/auth/custom/reset-password']);

        User::factory()->create([
            'email' => 'english.reset@example.com',
            'locale' => 'en',
            'provider' => 'email',
        ]);

        $response = $this->postJson(self::ENDPOINT_AUTH.'/password/forgot', [
            'email' => 'english.reset@example.com',
            'locale' => 'es',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath(
            'message',
            'If the email belongs to a password account, we sent a link to change it.'
        );

        Mail::assertSent(
            PasswordResetLink::class,
            fn (PasswordResetLink $mail): bool => $mail->hasTo('english.reset@example.com')
                && str_contains($mail->resetUrl, 'locale=en')
                && str_contains($mail->render(), 'Reset your password')
        );
    }

    #[Test]
    public function forgot_password_informs_google_users_to_use_google_sign_in(): void
    {
        Mail::fake();

        User::factory()->create([
            'email' => 'google.reset@example.com',
            'google_id' => 'google-reset-123',
            'locale' => 'en',
            'provider' => 'google',
        ]);

        $response = $this->postJson(self::ENDPOINT_AUTH.'/password/forgot', [
            'email' => 'google.reset@example.com',
            'locale' => 'en',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('provider', 'google');
        $response->assertJsonPath('message', 'This account uses Google sign-in. Use the Google button to continue.');

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'google.reset@example.com']);
        Mail::assertNotSent(PasswordResetLink::class);
    }

    #[Test]
    public function password_reset_token_changes_password_once_and_sends_confirmation(): void
    {
        Mail::fake();
        config(['password-reset.redirect_url' => 'http://localhost:3000/auth/custom/reset-password']);

        $user = User::factory()->create([
            'email' => 'change.password@example.com',
            'locale' => 'en',
            'password' => 'OldPass123!',
            'provider' => 'email',
        ]);
        $token = null;

        $this->postJson(self::ENDPOINT_AUTH.'/password/forgot', [
            'email' => $user->email,
            'locale' => 'en',
        ])->assertStatus(200);

        Mail::assertSent(PasswordResetLink::class, function (PasswordResetLink $mail) use (&$token): bool {
            parse_str((string) parse_url($mail->resetUrl, PHP_URL_QUERY), $query);
            $token = $query['token'] ?? null;

            return $mail->hasTo('change.password@example.com');
        });

        $this->assertIsString($token);

        $this->postJson(self::ENDPOINT_AUTH.'/password/reset/validate', [
            'email' => $user->email,
            'locale' => 'en',
            'token' => $token,
        ])->assertStatus(200)->assertJsonPath('status', 'valid');

        $resetResponse = $this->postJson(self::ENDPOINT_AUTH.'/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewPass123!',
            'password_confirmation' => 'NewPass123!',
        ]);

        $resetResponse->assertStatus(200);
        $resetResponse->assertJsonPath('status', 'changed');

        $user->refresh();
        $this->assertTrue(Hash::check('NewPass123!', $user->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

        Mail::assertSent(
            PasswordChanged::class,
            fn (PasswordChanged $mail): bool => $mail->hasTo('change.password@example.com')
        );

        $this->postJson(self::ENDPOINT_AUTH.'/password/reset/validate', [
            'email' => $user->email,
            'locale' => 'en',
            'token' => $token,
        ])->assertStatus(422)->assertJsonPath('status', 'invalid');

        $this->postJson(self::ENDPOINT_AUTH.'/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'AnotherPass123!',
            'password_confirmation' => 'AnotherPass123!',
        ])->assertStatus(422)->assertJsonPath('status', 'invalid');

        $this->postJson(self::ENDPOINT_AUTH.'/get-token', [
            'email' => $user->email,
            'password' => 'OldPass123!',
        ])->assertStatus(403);

        $this->postJson(self::ENDPOINT_AUTH.'/get-token', [
            'email' => $user->email,
            'password' => 'NewPass123!',
        ])->assertStatus(200)->assertJsonStructure(['access_token']);
    }

    #[Test]
    public function password_reset_rejects_expired_token(): void
    {
        Mail::fake();
        config(['password-reset.expires_in_minutes' => 60]);

        $user = User::factory()->create([
            'email' => 'expired.reset@example.com',
            'locale' => 'en',
            'provider' => 'email',
        ]);

        DB::table('password_reset_tokens')->insert([
            'created_at' => now()->subMinutes(61),
            'email' => $user->email,
            'token' => hash('sha256', 'expired-token'),
        ]);

        $this->postJson(self::ENDPOINT_AUTH.'/password/reset/validate', [
            'email' => $user->email,
            'locale' => 'en',
            'token' => 'expired-token',
        ])->assertStatus(410)->assertJsonPath('status', 'expired');

        $response = $this->postJson(self::ENDPOINT_AUTH.'/password/reset', [
            'email' => $user->email,
            'token' => 'expired-token',
            'password' => 'NewPass123!',
            'password_confirmation' => 'NewPass123!',
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('status', 'expired');
        Mail::assertNotSent(PasswordChanged::class);
    }

    #[Test]
    public function password_reset_verifies_unverified_email_user_after_successful_reset(): void
    {
        Mail::fake();
        config(['password-reset.redirect_url' => 'http://localhost:3000/auth/custom/reset-password']);

        $user = User::factory()->unverified()->create([
            'email' => 'pending.reset@example.com',
            'locale' => 'es',
            'password' => 'OldPass123!',
            'provider' => 'email',
        ]);
        $token = null;

        $this->postJson(self::ENDPOINT_AUTH.'/password/forgot', [
            'email' => $user->email,
            'locale' => 'en',
        ])->assertStatus(200);

        Mail::assertSent(PasswordResetLink::class, function (PasswordResetLink $mail) use (&$token): bool {
            parse_str((string) parse_url($mail->resetUrl, PHP_URL_QUERY), $query);
            $token = $query['token'] ?? null;

            return $mail->hasTo('pending.reset@example.com')
                && str_contains($mail->resetUrl, 'locale=es');
        });

        $this->assertIsString($token);
        $this->assertNull($user->email_verified_at);

        $this->postJson(self::ENDPOINT_AUTH.'/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewPass123!',
            'password_confirmation' => 'NewPass123!',
        ])->assertStatus(200)->assertJsonPath('status', 'changed');

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('NewPass123!', $user->password));

        Mail::assertSent(
            PasswordChanged::class,
            fn (PasswordChanged $mail): bool => $mail->hasTo('pending.reset@example.com')
                && str_contains($mail->render(), 'Contraseña actualizada')
        );

        $this->postJson(self::ENDPOINT_AUTH.'/get-token', [
            'email' => $user->email,
            'password' => 'NewPass123!',
        ])->assertStatus(200)->assertJsonStructure(['access_token']);
    }

    #[Test]
    public function active_session_password_change_updates_password_revokes_other_tokens_and_sends_email(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'active.change@example.com',
            'locale' => 'es',
            'password' => 'OldPass123!',
            'provider' => 'email',
        ]);
        $currentToken = $user->createToken('current-token');
        $otherToken = $user->createToken('other-token');

        $response = $this->withHeader('Authorization', 'Bearer '.$currentToken->plainTextToken)
            ->postJson(self::ENDPOINT_AUTH.'/password/change', [
                'current_password' => 'OldPass123!',
                'password' => 'NewPass123!',
                'password_confirmation' => 'NewPass123!',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'changed');
        $response->assertJsonPath('message', 'Tu contraseña fue actualizada correctamente.');

        $user->refresh();
        $this->assertTrue(Hash::check('NewPass123!', $user->password));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $currentToken->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherToken->accessToken->id]);

        Mail::assertSent(
            PasswordChanged::class,
            fn (PasswordChanged $mail): bool => $mail->hasTo('active.change@example.com')
                && str_contains($mail->render(), 'Contraseña actualizada')
        );

        $this->flushHeaders();

        $this->postJson(self::ENDPOINT_AUTH.'/get-token', [
            'email' => $user->email,
            'password' => 'OldPass123!',
        ])->assertStatus(403);

        $this->postJson(self::ENDPOINT_AUTH.'/get-token', [
            'email' => $user->email,
            'password' => 'NewPass123!',
        ])->assertStatus(200)->assertJsonStructure(['access_token']);
    }

    #[Test]
    public function active_session_password_change_rejects_wrong_current_password(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'wrong-current@example.com',
            'locale' => 'en',
            'password' => 'OldPass123!',
            'provider' => 'email',
        ]);
        $token = $user->createToken('current-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_AUTH.'/password/change', [
                'current_password' => 'WrongPass123!',
                'password' => 'NewPass123!',
                'password_confirmation' => 'NewPass123!',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Current password is incorrect.');

        $user->refresh();
        $this->assertTrue(Hash::check('OldPass123!', $user->password));
        Mail::assertNotSent(PasswordChanged::class);
    }

    #[Test]
    public function active_session_password_change_rejects_google_accounts(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'google.change@example.com',
            'google_id' => 'google-change-123',
            'locale' => 'en',
            'provider' => 'google',
        ]);
        $token = $user->createToken('current-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_AUTH.'/password/change', [
                'current_password' => 'password',
                'password' => 'NewPass123!',
                'password_confirmation' => 'NewPass123!',
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('provider', 'google');
        $response->assertJsonPath('message', 'This account uses Google sign-in. Use the Google button to continue.');
        Mail::assertNotSent(PasswordChanged::class);
    }

    #[Test]
    public function login_history_returns_latest_events_first_with_ten_item_pagination(): void
    {
        $user = User::factory()->create(['email' => 'history@example.com']);
        $token = $user->createToken('current-token')->plainTextToken;

        foreach (range(1, 12) as $index) {
            AuthLoginEvent::create([
                'user_id' => $user->id,
                'type' => 'credential',
                'ip_address' => '10.0.0.'.$index,
                'user_agent' => 'Browser '.$index,
                'created_at' => now()->subMinutes(12 - $index),
                'updated_at' => now()->subMinutes(12 - $index),
            ]);
        }

        $firstPage = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT_AUTH.'/login-history?per_page=10&page=1');

        $firstPage->assertStatus(200);
        $firstPage->assertJsonPath('data.pagination.current_page', 1);
        $firstPage->assertJsonPath('data.pagination.per_page', 10);
        $firstPage->assertJsonPath('data.pagination.total', 12);
        $this->assertCount(10, $firstPage->json('data.events'));
        $firstPage->assertJsonPath('data.events.0.user_agent', 'Browser 12');
        $firstPage->assertJsonPath('data.events.9.user_agent', 'Browser 3');

        $secondPage = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT_AUTH.'/login-history?per_page=10&page=2');

        $secondPage->assertStatus(200);
        $secondPage->assertJsonPath('data.pagination.current_page', 2);
        $this->assertCount(2, $secondPage->json('data.events'));
        $secondPage->assertJsonPath('data.events.0.user_agent', 'Browser 2');
        $secondPage->assertJsonPath('data.events.1.user_agent', 'Browser 1');
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
        config([
            'mail.branding.admin_url' => 'https://admin.example.com',
            'mail.branding.home_url' => 'https://web.example.com',
            'mail.branding.logo_url' => 'https://cdn.example.com/bigmelo-logo.png',
        ]);

        $englishUser = User::factory()->make(['locale' => 'en', 'name' => 'English User']);
        $spanishUser = User::factory()->make(['locale' => 'es', 'name' => 'Usuario Espanol']);

        $englishVerification = (new VerifyEmailAddress($englishUser, 'https://example.com/verify'))->render();
        $spanishVerification = (new VerifyEmailAddress($spanishUser, 'https://example.com/verificar'))->render();
        $englishWelcome = (new WelcomeEmail($englishUser))->render();
        $spanishWelcome = (new WelcomeEmail($spanishUser))->render();
        $englishPasswordReset = (new PasswordResetLink($englishUser, 'https://example.com/reset'))->render();
        $spanishPasswordReset = (new PasswordResetLink($spanishUser, 'https://example.com/restablecer'))->render();
        $englishPasswordChanged = (new PasswordChanged($englishUser))->render();
        $spanishPasswordChanged = (new PasswordChanged($spanishUser))->render();

        $this->assertStringContainsString('Confirm your email', $englishVerification);
        $this->assertStringContainsString('Confirma tu correo', $spanishVerification);
        $this->assertStringContainsString('Your account is confirmed', $englishWelcome);
        $this->assertStringContainsString('Tu cuenta ya está confirmada', $spanishWelcome);
        $this->assertStringContainsString('Reset your password', $englishPasswordReset);
        $this->assertStringContainsString('Restablece tu contraseña', $spanishPasswordReset);
        $this->assertStringContainsString('Password updated', $englishPasswordChanged);
        $this->assertStringContainsString('Contraseña actualizada', $spanishPasswordChanged);
        $this->assertStringContainsString('https://cdn.example.com/bigmelo-logo.png', $englishVerification);
        $this->assertStringContainsString('https://admin.example.com', $englishWelcome);
        $this->assertStringContainsString('https://admin.example.com', $spanishPasswordChanged);
        $this->assertStringNotContainsString('https://web.example.com', $englishWelcome);
        $this->assertStringNotContainsString('https://web.example.com', $spanishPasswordChanged);
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
