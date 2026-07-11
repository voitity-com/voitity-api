<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class AuthMailDeliveryTest extends TestCase
{
    public function test_sign_up_returns_controlled_error_when_verification_email_cannot_be_sent(): void
    {
        $email = 'mail-failure-signup@example.com';

        Mail::shouldReceive('to')
            ->once()
            ->with($email)
            ->andReturnSelf();

        Mail::shouldReceive('send')
            ->once()
            ->andThrow(new TransportException('SES recipient is not verified.'));

        $response = $this->postJson('/api/auth/sign-up', [
            'name' => 'Mail Failure',
            'email' => $email,
            'locale' => 'en',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response
            ->assertStatus(503)
            ->assertJson([
                'message' => 'We could not send the verification email. Please try again later.',
                'email_delivery_failed' => true,
                'email_verification_required' => true,
            ]);

        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    public function test_forgot_password_returns_controlled_error_when_reset_email_cannot_be_sent(): void
    {
        $user = User::factory()->create([
            'email' => 'mail-failure-reset@example.com',
            'locale' => 'en',
            'provider' => 'email',
        ]);

        Mail::shouldReceive('to')
            ->once()
            ->with($user->email)
            ->andReturnSelf();

        Mail::shouldReceive('send')
            ->once()
            ->andThrow(new TransportException('SES recipient is not verified.'));

        $response = $this->postJson('/api/auth/password/forgot', [
            'email' => $user->email,
            'locale' => 'en',
        ]);

        $response
            ->assertStatus(503)
            ->assertJson([
                'message' => 'We could not send the password reset email. Please try again later.',
                'email_delivery_failed' => true,
            ]);

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }
}
