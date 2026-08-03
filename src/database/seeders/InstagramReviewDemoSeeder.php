<?php

namespace Database\Seeders;

use App\Enums\ProfileStatus;
use App\Models\FeatureFlag;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\ProfileConversationMessage;
use App\Models\ProfileFeatureSetting;
use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InstagramReviewDemoSeeder extends Seeder
{
    private const ASSET_BASE_URL = 'http://localhost:8100/assets';

    private const EMAIL = 'codex.instagram.test@bigmelo.local';

    private const PASSWORD = 'test123';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('InstagramReviewDemoSeeder is restricted to local and testing environments.');
        }

        $user = LocalTestUserSeeder::createUser(self::EMAIL, self::PASSWORD);
        $user->forceFill(['name' => 'Sofía Rivera'])->save();

        DB::transaction(function () use ($user): void {
            $profile = Profile::withTrashed()
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->first() ?? new Profile(['user_id' => $user->id]);

            if ($profile->trashed()) {
                $profile->restore();
            }

            $profile->forceFill([
                'alias' => 'sofia-rivera',
                'name' => 'Sofía Rivera',
                'description' => 'Fashion creator sharing signature looks from Paris, London, and New York. See more pieces on my Instagram or contact me for sizing and availability.',
                'genre' => 'female',
                'personality' => 'Warm, elegant, and helpful',
                'locale' => 'en',
                'profession_key' => 'custom',
                'profession_template_version' => '2026-07',
                'active' => true,
                'status' => ProfileStatus::Published,
                'data' => [
                    'me' => [
                        'city' => 'New York',
                        'languages' => ['English', 'Spanish'],
                    ],
                    'work' => [
                        'title' => 'Fashion creator and personal stylist',
                        'contact' => 'Contact me for details about the pieces shown in my looks.',
                    ],
                    'projects' => [],
                ],
                'networks' => [
                    'instagram' => 'https://www.instagram.com/sofia.rivera.style/',
                ],
            ]);
            $profile->save();

            FeatureFlag::query()->updateOrCreate(
                ['key' => 'integrations.instagram'],
                [
                    'name' => 'Instagram',
                    'enabled' => true,
                    'metadata' => ['group' => 'integrations', 'provider' => 'instagram'],
                ],
            );
            ProfileFeatureSetting::query()->updateOrCreate(
                ['profile_id' => $profile->id, 'feature_key' => 'integrations.instagram'],
                ['enabled' => true],
            );

            ProfileAvatar::query()
                ->where('profile_id', $profile->id)
                ->where('status', ProfileAvatar::STATUS_ACTIVE)
                ->update(['status' => ProfileAvatar::STATUS_INACTIVE]);
            ProfileAvatar::query()->updateOrCreate(
                [
                    'profile_id' => $profile->id,
                    'file' => self::ASSET_BASE_URL.'/sofia-avatar.png',
                ],
                [
                    'user_id' => $user->id,
                    'status' => ProfileAvatar::STATUS_ACTIVE,
                ],
            );

            ProfileConversationMessage::query()->updateOrCreate(
                [
                    'profile_id' => $profile->id,
                    'type' => ProfileConversationMessage::TYPE_INITIAL,
                ],
                [
                    'text' => 'Hi, I’m Sofía Rivera. Ask me about the outfits I wore in Paris, London, or New York, or how to contact me for more information.',
                    'status' => ProfileConversationMessage::STATUS_READY,
                    'text_hash' => hash('sha256', 'instagram-review-demo-initial'),
                    'metadata' => ['demo' => true, 'review_demo' => true],
                ],
            );
            ProfileConversationMessage::query()->updateOrCreate(
                [
                    'profile_id' => $profile->id,
                    'type' => ProfileConversationMessage::TYPE_FALLBACK_NO_ANSWER,
                ],
                [
                    'text' => 'I do not have that information yet. You can see more pieces on my Instagram or contact me for details.',
                    'status' => ProfileConversationMessage::STATUS_READY,
                    'text_hash' => hash('sha256', 'instagram-review-demo-fallback'),
                    'metadata' => ['demo' => true, 'review_demo' => true],
                ],
            );

            $integration = ProfileIntegration::query()->updateOrCreate(
                [
                    'profile_id' => $profile->id,
                    'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
                ],
                [
                    'user_id' => $user->id,
                    'provider_user_id' => 'demo-sofia-rivera',
                    'username' => 'sofia.rivera.style',
                    'access_token' => null,
                    'refresh_token' => null,
                    'token_type' => 'Bearer',
                    'scopes' => ['instagram_business_basic'],
                    'expires_at' => now()->addDays(60),
                    'refresh_expires_at' => null,
                    'last_synced_at' => now(),
                    'status' => ProfileIntegration::STATUS_CONNECTED,
                    'metadata' => [
                        'demo' => true,
                        'review_demo' => true,
                        'notice' => 'Local test data for the Instagram app-review walkthrough.',
                    ],
                ],
            );

            $integration->media()->delete();

            foreach ($this->mediaDefinitions() as $definition) {
                ProfileIntegrationMedia::query()->create([
                    'profile_integration_id' => $integration->id,
                    'profile_id' => $profile->id,
                    'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
                    ...$definition,
                    'age_restricted' => false,
                    'selected' => true,
                    'metadata' => [
                        'demo' => true,
                        'review_demo' => true,
                    ],
                ]);
            }

            $this->command?->info("Instagram review demo seeded for profile {$profile->id} ({$profile->alias}).");
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mediaDefinitions(): array
    {
        return [
            [
                'provider_media_id' => 'instagram-review-demo-paris',
                'media_type' => 'IMAGE',
                'media_url' => self::ASSET_BASE_URL.'/sofia-paris.png',
                'thumbnail_url' => self::ASSET_BASE_URL.'/sofia-paris.png',
                'permalink' => 'https://www.instagram.com/sofia.rivera.style/',
                'caption' => 'An emerald coat for an autumn walk by the Seine. See more pieces on my Instagram, or contact me for sizing and availability.',
                'observation' => 'Sofía wears an emerald statement coat in Paris, France.',
                'taken_at' => now()->subDays(2),
            ],
            [
                'provider_media_id' => 'instagram-review-demo-london',
                'media_type' => 'IMAGE',
                'media_url' => self::ASSET_BASE_URL.'/sofia-london.png',
                'thumbnail_url' => self::ASSET_BASE_URL.'/sofia-london.png',
                'permalink' => 'https://www.instagram.com/sofia.rivera.style/',
                'caption' => 'London layers: camel trench, burgundy silk, and tailored black trousers. See more looks on my Instagram, or contact me for more information.',
                'observation' => 'Sofía styles a camel trench and tailored separates in London, United Kingdom.',
                'taken_at' => now()->subDays(5),
            ],
            [
                'provider_media_id' => 'instagram-review-demo-new-york',
                'media_type' => 'VIDEO',
                'media_url' => self::ASSET_BASE_URL.'/sofia-new-york-video.mp4',
                'thumbnail_url' => self::ASSET_BASE_URL.'/sofia-new-york.png',
                'permalink' => 'https://www.instagram.com/sofia.rivera.style/',
                'caption' => 'A cobalt blazer for a bright morning in SoHo. Watch the full look on my Instagram, or contact me to learn more about the pieces.',
                'observation' => 'Sofía wears a cobalt blazer in SoHo, New York City.',
                'taken_at' => now()->subDays(8),
            ],
        ];
    }
}
