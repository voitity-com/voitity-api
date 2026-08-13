<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Enums\ProfileDomainStatus;
use App\Enums\ProfileStatus;
use App\Jobs\ProfileDomains\RefreshProfileDomain;
use App\Models\Profile;
use App\Models\ProfileDomain;
use App\Models\User;
use RuntimeException;

class ProfileDomainControllerTest extends TestAPI
{
    public function test_owner_can_configure_verify_read_and_disconnect_a_domain_locally(): void
    {
        config(['profile-domains.default' => 'local']);
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create();
        $token = $user->createToken('domain', ['profile:read', 'profile:write'])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/domain")
            ->assertOk()
            ->assertJsonPath('data.domain', null);

        $this->withToken($token)
            ->postJson("/api/profile/{$profile->id}/domain", ['hostname' => ' Profile.Example.org. '])
            ->assertAccepted()
            ->assertJsonPath('data.domain.hostname', 'profile.example.org')
            ->assertJsonPath('data.domain.status', ProfileDomainStatus::PendingDns->value)
            ->assertJsonPath('data.domain.dns_records.0.type', 'CNAME_OR_ALIAS')
            ->assertJsonPath('data.domain.dns_records.0.value', 'profiles.localhost')
            ->assertJsonPath('data.domain.public_url', 'http://profile.example.org:3001');

        $this->withToken($token)
            ->postJson("/api/profile/{$profile->id}/domain/verify")
            ->assertAccepted();

        $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/domain")
            ->assertOk()
            ->assertJsonPath('data.domain.status', ProfileDomainStatus::Active->value)
            ->assertJsonPath('data.domain.active', true);

        $this->withToken($token)
            ->deleteJson("/api/profile/{$profile->id}/domain")
            ->assertAccepted();

        $this->assertDatabaseMissing('profile_domains', ['profile_id' => $profile->id]);
    }

    public function test_domain_endpoints_validate_abilities_ownership_and_uniqueness(): void
    {
        config(['profile-domains.default' => 'local']);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $profile = Profile::factory()->for($owner)->create();
        $otherProfile = Profile::factory()->for($other)->create();
        $ownerToken = $owner->createToken('owner', ['profile:read', 'profile:write'])->plainTextToken;

        foreach (['https://profile.example.org', 'profile.example.org/path', '*.example.org', 'localhost', 'admin.bigmelo.com'] as $hostname) {
            $this->withToken($ownerToken)
                ->postJson("/api/profile/{$profile->id}/domain", ['hostname' => $hostname])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['hostname']);
        }

        ProfileDomain::query()->create([
            'profile_id' => $otherProfile->id,
            'hostname' => 'used.example.org',
            'provider' => 'local',
            'status' => ProfileDomainStatus::Active->value,
        ]);

        $this->withToken($ownerToken)
            ->postJson("/api/profile/{$profile->id}/domain", ['hostname' => 'used.example.org'])
            ->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $readOnlyToken = $owner->createToken('read', ['profile:read'])->plainTextToken;
        $this->withToken($readOnlyToken)
            ->postJson("/api/profile/{$profile->id}/domain", ['hostname' => 'new.example.org'])
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $otherToken = $other->createToken('other', ['profile:read', 'profile:write'])->plainTextToken;
        $this->withToken($otherToken)
            ->getJson("/api/profile/{$profile->id}/domain")
            ->assertNotFound();
    }

    public function test_public_domain_lookup_only_exposes_active_published_profile(): void
    {
        $profile = Profile::factory()->for(User::factory())->create([
            'active' => true,
            'status' => ProfileStatus::Published,
            'alias' => 'custom-domain-profile',
        ]);
        $domain = ProfileDomain::query()->create([
            'profile_id' => $profile->id,
            'hostname' => 'hello.example.org',
            'provider' => 'local',
            'status' => ProfileDomainStatus::PendingDns->value,
        ]);

        $this->getJson('/api/public/profiles/by-domain/hello.example.org')->assertNotFound();

        $domain->update(['status' => ProfileDomainStatus::Active->value]);

        $this->getJson('/api/public/profiles/by-domain/HELLO.example.org')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.alias', 'custom-domain-profile');

        $profile->update(['active' => false]);
        $this->getJson('/api/public/profiles/by-domain/hello.example.org')->assertNotFound();
    }

    public function test_failed_disconnect_cannot_be_verified_and_can_be_disconnected_again(): void
    {
        config(['profile-domains.default' => 'local']);
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create();
        $domain = ProfileDomain::query()->create([
            'profile_id' => $profile->id,
            'hostname' => 'disconnect.example.org',
            'provider' => 'local',
            'status' => ProfileDomainStatus::Failed->value,
            'last_error_code' => 'disconnect',
            'last_error_message' => 'The domain could not be disconnected yet. Please try again.',
        ]);
        $token = $user->createToken('domain', ['profile:read', 'profile:write'])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/profile/{$profile->id}/domain/verify")
            ->assertConflict()
            ->assertJsonPath('data.domain.retryable', false);

        $this->withToken($token)
            ->deleteJson("/api/profile/{$profile->id}/domain")
            ->assertAccepted();

        $this->assertDatabaseMissing('profile_domains', ['id' => $domain->id]);
    }

    public function test_background_verification_failure_keeps_an_active_domain_available(): void
    {
        $profile = Profile::factory()->for(User::factory())->create();
        $domain = ProfileDomain::query()->create([
            'profile_id' => $profile->id,
            'hostname' => 'healthy.example.org',
            'provider' => 'local',
            'status' => ProfileDomainStatus::Active->value,
        ]);

        (new RefreshProfileDomain($domain->id))->failed(new RuntimeException('Temporary provider outage'));

        $domain->refresh();
        $this->assertSame(ProfileDomainStatus::Active, $domain->status);
        $this->assertSame('background_verify', $domain->last_error_code);
        $this->assertNotNull($domain->last_checked_at);
    }
}
