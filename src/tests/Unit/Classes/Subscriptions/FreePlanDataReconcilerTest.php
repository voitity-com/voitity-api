<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\Subscriptions;

use App\Classes\Subscriptions\SubscriptionPlanAssigner;
use App\Enums\AvatarGenerationStatus;
use App\Enums\AvatarVariant;
use App\Enums\ProfileDomainStatus;
use App\Enums\ProfileProductStatus;
use App\Enums\ProfileSourceStatus;
use App\Enums\ProfileSourceType;
use App\Enums\ProfileStatus;
use App\Enums\SubscriptionPlan;
use App\Models\Profile;
use App\Models\ProfileAppearance;
use App\Models\ProfileAvatar;
use App\Models\ProfileDomain;
use App\Models\ProfileIntegration;
use App\Models\ProfileProduct;
use App\Models\ProfileSource;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class FreePlanDataReconcilerTest extends TestCase
{
    public function test_downgrade_locks_paid_data_and_upgrade_restores_it(): void
    {
        $user = User::factory()->create();
        $assigner = app(SubscriptionPlanAssigner::class);
        $assigner->assign($user, SubscriptionPlan::Admin, ['billing_mode' => 'admin_grant']);
        $olderProfile = Profile::factory()->for($user)->create([
            'active' => true,
            'status' => ProfileStatus::Published,
            'updated_at' => now()->subDay(),
        ]);
        $profile = Profile::factory()->for($user)->create([
            'active' => true,
            'status' => ProfileStatus::Published,
            'updated_at' => now(),
        ]);
        $appearance = ProfileAppearance::query()->create([
            'profile_id' => $profile->id,
            'template_key' => 'profile05',
        ]);
        $avatar = ProfileAvatar::query()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'video_duration_seconds' => 2,
            'original_file' => 'https://cdn.example.com/original.jpg',
            'file' => 'https://cdn.example.com/animation.mp4',
            'status' => ProfileAvatar::STATUS_ACTIVE,
            'generation_status' => AvatarGenerationStatus::Completed,
            'selected_variant' => AvatarVariant::Animation,
        ]);
        $instagram = $this->integration($profile, $user, ProfileIntegration::PROVIDER_INSTAGRAM);
        $youtube = $this->integration($profile, $user, ProfileIntegration::PROVIDER_YOUTUBE);
        $firstProduct = $this->product($profile, $user, 'Producto uno');
        $secondProduct = $this->product($profile, $user, 'Producto dos');
        $firstSource = $this->source($profile, $user, 'Fuente uno', now()->subMinute());
        $secondSource = $this->source($profile, $user, 'Fuente dos', now());
        $domain = ProfileDomain::query()->create([
            'profile_id' => $profile->id,
            'hostname' => 'profile.example.com',
            'status' => ProfileDomainStatus::Active,
            'provider' => 'cloudflare',
            'provider_tenant_id' => 'tenant-123',
        ]);

        $free = $assigner->assign($user, SubscriptionPlan::Free, ['billing_mode' => 'free_recurring']);

        $this->assertSame(SubscriptionPlan::Free, $free->plan);
        $this->assertFalse((bool) $olderProfile->fresh()->active);
        $this->assertSame(ProfileStatus::Hidden, $olderProfile->fresh()->status);
        $this->assertTrue((bool) $profile->fresh()->active);
        $this->assertSame(AvatarVariant::Original, $avatar->fresh()->selected_variant);
        $this->assertSame($avatar->original_file, $avatar->fresh()->file);
        $this->assertSame(0, $avatar->fresh()->video_duration_seconds);
        $this->assertSame('profile01', $appearance->fresh()->template_key);
        $this->assertTrue((bool) data_get($appearance->fresh()->metadata, '_free_plan.locked'));
        $this->assertSame(ProfileIntegration::STATUS_REVOKED, $instagram->fresh()->status);
        $this->assertSame(ProfileIntegration::STATUS_REVOKED, $youtube->fresh()->status);
        $this->assertSame(ProfileProductStatus::Draft, $firstProduct->fresh()->status);
        $this->assertSame(ProfileProductStatus::Published, $secondProduct->fresh()->status);
        $this->assertTrue((bool) data_get($firstSource->fresh()->metadata, '_free_plan.locked'));
        $this->assertFalse((bool) data_get($secondSource->fresh()->metadata, '_free_plan.locked'));
        $this->assertSame(ProfileDomainStatus::Failed, $domain->fresh()->status);
        $this->assertSame('free_plan_locked', $domain->fresh()->last_error_code);

        $upgraded = $assigner->assign($user, SubscriptionPlan::Admin, ['billing_mode' => 'admin_grant']);

        $this->assertSame(SubscriptionPlan::Admin, $upgraded->plan);
        $this->assertTrue((bool) $olderProfile->fresh()->active);
        $this->assertSame(ProfileStatus::Published, $olderProfile->fresh()->status);
        $this->assertSame(AvatarVariant::Animation, $avatar->fresh()->selected_variant);
        $this->assertSame('https://cdn.example.com/animation.mp4', $avatar->fresh()->file);
        $this->assertSame(2, $avatar->fresh()->video_duration_seconds);
        $this->assertNull(data_get($avatar->fresh()->metadata, '_free_plan'));
        $this->assertSame('profile05', $appearance->fresh()->template_key);
        $this->assertNull(data_get($appearance->fresh()->metadata, '_free_plan'));
        $this->assertSame(ProfileIntegration::STATUS_CONNECTED, $instagram->fresh()->status);
        $this->assertSame(ProfileIntegration::STATUS_CONNECTED, $youtube->fresh()->status);
        $this->assertSame(ProfileProductStatus::Published, $firstProduct->fresh()->status);
        $this->assertSame(ProfileProductStatus::Published, $secondProduct->fresh()->status);
        $this->assertNull(data_get($firstSource->fresh()->metadata, '_free_plan'));
        $this->assertSame(ProfileDomainStatus::PendingDns, $domain->fresh()->status);
        $this->assertNull($domain->fresh()->last_error_code);
    }

    private function integration(Profile $profile, User $user, string $provider): ProfileIntegration
    {
        return ProfileIntegration::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => $provider.'-user',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
    }

    private function product(Profile $profile, User $user, string $name): ProfileProduct
    {
        $slug = Str::slug($name);

        return ProfileProduct::query()->create([
            'public_id' => (string) Str::uuid(),
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'slug' => $slug,
            'name' => $name,
            'description' => 'Descripción.',
            'image_source' => 'remote',
            'image_url' => "https://images.example.com/{$slug}.jpg",
            'destination_type' => 'external_url',
            'destination_url' => "https://shop.example.com/{$slug}",
            'status' => ProfileProductStatus::Published,
            'fingerprint' => hash('sha256', $name),
            'published_at' => now(),
        ]);
    }

    private function source(Profile $profile, User $user, string $name, mixed $updatedAt): ProfileSource
    {
        $source = ProfileSource::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'type' => ProfileSourceType::Manual,
            'name' => $name,
            'status' => ProfileSourceStatus::Indexed,
            'extracted_text' => $name,
        ]);
        $source->timestamps = false;
        $source->forceFill([
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ])->save();

        return $source->refresh();
    }
}
