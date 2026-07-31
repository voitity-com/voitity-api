<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Mail\SupportRequestReceived;
use App\Models\Profile;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

class SupportRequestControllerTest extends TestAPI
{
    private const ENDPOINT = '/api/support-requests';

    #[Test]
    public function unauthenticated_users_cannot_create_support_requests(): void
    {
        $this->postJson(self::ENDPOINT, [
            'description' => 'Necesito ayuda con mi perfil.',
        ])->assertUnauthorized();
    }

    #[Test]
    public function users_without_support_ability_cannot_create_support_requests(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['profile:read'])->plainTextToken;

        $this->withToken($token)->postJson(self::ENDPOINT, [
            'description' => 'Necesito ayuda con mi perfil.',
        ])->assertForbidden();
    }

    #[Test]
    public function authenticated_users_can_create_a_request_for_an_active_profile(): void
    {
        Mail::fake();
        config(['support.recipient_email' => 'support@bigmelo.com']);

        $user = User::factory()->create(['email' => 'owner@bigmelo.test']);
        $profile = Profile::factory()->create([
            'user_id' => $user->id,
            'alias' => 'perfil-activo',
            'active' => true,
        ]);
        $token = $user->createToken('test-token', ['support:create'])->plainTextToken;

        $response = $this->withToken($token)
            ->withHeader('User-Agent', 'Support Feature Test')
            ->postJson(self::ENDPOINT, [
                'email' => 'spoofed@example.com',
                'profile_id' => $profile->id,
                'description' => '  Necesito ayuda para publicar los cambios de mi perfil.  ',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Support request received successfully.')
            ->assertJsonStructure(['data' => ['id']]);

        $supportRequest = SupportRequest::query()->firstOrFail();

        $this->assertSame($user->id, $supportRequest->user_id);
        $this->assertSame($profile->id, $supportRequest->profile_id);
        $this->assertSame('owner@bigmelo.test', $supportRequest->email);
        $this->assertSame('perfil-activo', $supportRequest->profile_alias);
        $this->assertSame('Necesito ayuda para publicar los cambios de mi perfil.', $supportRequest->description);
        $this->assertSame('Support Feature Test', $supportRequest->user_agent);
        $this->assertNotNull($supportRequest->notified_at);

        Mail::assertSent(
            SupportRequestReceived::class,
            fn (SupportRequestReceived $mail): bool => $mail->hasTo('support@bigmelo.com')
                && $mail->supportRequest->is($supportRequest)
        );
    }

    #[Test]
    public function an_owned_inactive_profile_can_be_selected(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $profile = Profile::factory()->create([
            'user_id' => $user->id,
            'alias' => 'perfil-inactivo',
            'active' => false,
        ]);
        $token = $user->createToken('test-token', ['support:create'])->plainTextToken;

        $this->withToken($token)->postJson(self::ENDPOINT, [
            'profile_id' => $profile->id,
            'description' => 'Necesito ayuda con este perfil inactivo.',
        ])->assertCreated();

        $this->assertDatabaseHas('support_requests', [
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'profile_alias' => 'perfil-inactivo',
        ]);
    }

    #[Test]
    public function profile_is_optional_and_no_captcha_is_required(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['support:create'])->plainTextToken;

        $this->withToken($token)->postJson(self::ENDPOINT, [
            'profile_id' => null,
            'description' => 'Necesito ayuda con la configuración de mi cuenta.',
        ])->assertCreated();

        $this->assertDatabaseHas('support_requests', [
            'user_id' => $user->id,
            'profile_id' => null,
            'email' => $user->email,
        ]);
    }

    #[Test]
    public function users_cannot_select_a_profile_owned_by_someone_else(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $foreignProfile = Profile::factory()->create();
        $token = $user->createToken('test-token', ['support:create'])->plainTextToken;

        $this->withToken($token)->postJson(self::ENDPOINT, [
            'profile_id' => $foreignProfile->id,
            'description' => 'Necesito ayuda con este perfil.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['profile_id']);

        $this->assertDatabaseCount('support_requests', 0);
        Mail::assertNothingSent();
    }

    #[Test]
    public function description_must_have_between_ten_and_three_thousand_characters(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['support:create'])->plainTextToken;

        $this->withToken($token)->postJson(self::ENDPOINT, [
            'description' => 'corta',
        ])->assertUnprocessable()->assertJsonValidationErrors(['description']);

        $this->withToken($token)->postJson(self::ENDPOINT, [
            'description' => str_repeat('a', 3001),
        ])->assertUnprocessable()->assertJsonValidationErrors(['description']);

        $this->assertDatabaseCount('support_requests', 0);
    }

    #[Test]
    public function support_requests_are_rate_limited_per_authenticated_user_and_ip(): void
    {
        Mail::fake();
        config(['support.rate_limit_per_minute' => 2]);

        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['support:create'])->plainTextToken;
        $payload = ['description' => 'Necesito ayuda con mi cuenta de Bigmelo.'];

        $this->withToken($token)->postJson(self::ENDPOINT, $payload)->assertCreated();
        $this->withToken($token)->postJson(self::ENDPOINT, $payload)->assertCreated();
        $this->withToken($token)->postJson(self::ENDPOINT, $payload)->assertTooManyRequests();

        $this->assertDatabaseCount('support_requests', 2);
    }
}
