<?php

namespace Database\Seeders;

use App\Enums\ChatAnalysisStatus;
use App\Enums\ChatEndReason;
use App\Enums\ChatStatus;
use App\Enums\ConversationCategory;
use App\Enums\ProfileInsightEventType;
use App\Enums\ProfileProductDestinationType;
use App\Enums\ProfileProductStatus;
use App\Enums\ProfileStatus;
use App\Models\Chat;
use App\Models\ChatAnalysis;
use App\Models\FeatureFlag;
use App\Models\Message;
use App\Models\Profile;
use App\Models\ProfileFeatureSetting;
use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use App\Models\ProfileInteractionEvent;
use App\Models\ProfileProduct;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProfileInsightsDemoSeeder extends Seeder
{
    private const PREFIX = 'demo:profile-insights:v1:';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('ProfileInsightsDemoSeeder is restricted to local and testing environments.');
        }

        $profileId = max(1, (int) env('INSIGHTS_DEMO_PROFILE_ID', 3));
        $profile = Profile::query()->findOrFail($profileId);

        DB::transaction(function () use ($profile): void {
            $this->prepareProfile($profile);
            [$products, $media] = $this->catalog($profile);
            $this->removePreviousDemoData($profile);
            $chats = $this->conversations($profile);
            $this->interactions($profile, $chats, $products, $media);
            $products['historical']->delete();
        });

        $this->command?->info("Profile insights demo data seeded for profile {$profile->id} ({$profile->alias}).");
    }

    private function prepareProfile(Profile $profile): void
    {
        $profile->forceFill([
            'active' => true,
            'status' => ProfileStatus::Published,
            'products_enabled' => true,
            'networks' => array_merge((array) $profile->networks, [
                'instagram' => 'https://instagram.com/bigmelo.demo',
                'tiktok' => 'https://www.tiktok.com/@bigmelo.demo',
                'onlyfans' => 'https://onlyfans.com/bigmelo.demo',
                'whatsapp' => 'https://wa.me/573001112233',
            ]),
        ])->save();

        foreach ([
            'products' => ['Products', 'products'],
            'integrations.instagram' => ['Instagram', 'integrations'],
            'integrations.tiktok' => ['TikTok', 'integrations'],
            'integrations.onlyfans' => ['OnlyFans', 'integrations'],
        ] as $key => [$name, $group]) {
            FeatureFlag::query()->updateOrCreate(['key' => $key], [
                'name' => $name,
                'enabled' => true,
                'metadata' => ['group' => $group],
            ]);
            ProfileFeatureSetting::query()->updateOrCreate(
                ['profile_id' => $profile->id, 'feature_key' => $key],
                ['enabled' => true],
            );
        }
    }

    /**
     * @return array{0: array<string, ProfileProduct>, 1: array<string, ProfileIntegrationMedia>}
     */
    private function catalog(Profile $profile): array
    {
        $productDefinitions = [
            'whatsapp' => [ProfileProductDestinationType::WhatsApp, null, 'Demo por WhatsApp', '3001112233'],
            'external' => [ProfileProductDestinationType::ExternalUrl, 'https://bigmelo.com', 'Demo web', null],
            'historical' => [ProfileProductDestinationType::ExternalUrl, 'https://bigmelo.com/archive', 'Demo histórico eliminado', null],
        ];
        $products = [];

        foreach ($productDefinitions as $key => [$destination, $url, $name, $phone]) {
            $products[$key] = ProfileProduct::query()->updateOrCreate(
                ['public_id' => match ($key) {
                    'whatsapp' => '10000000-0000-4000-8000-000000000001',
                    'external' => '10000000-0000-4000-8000-000000000002',
                    default => '10000000-0000-4000-8000-000000000003',
                }],
                [
                    'profile_id' => $profile->id,
                    'user_id' => $profile->user_id,
                    'slug' => "insights-demo-{$key}",
                    'name' => $name,
                    'description' => 'Producto local para validar eventos y métricas de Insights.',
                    'image_source' => 'url',
                    'image_url' => "https://picsum.photos/seed/bigmelo-{$key}/720/720",
                    'destination_type' => $destination,
                    'destination_url' => $url,
                    'country_code' => $phone ? '57' : null,
                    'phone_number' => $phone,
                    'status' => ProfileProductStatus::Published,
                    'fingerprint' => hash('sha256', self::PREFIX."product:{$profile->id}:{$key}"),
                    'published_at' => now()->subDays(45),
                    'metadata' => ['demo' => true],
                ],
            );
        }

        $media = [];

        foreach (['instagram', 'tiktok', 'onlyfans'] as $provider) {
            $integration = ProfileIntegration::query()->updateOrCreate(
                ['profile_id' => $profile->id, 'provider' => $provider],
                [
                    'user_id' => $profile->user_id,
                    'provider_user_id' => "insights-demo-{$provider}",
                    'username' => 'bigmelo.demo',
                    'status' => ProfileIntegration::STATUS_CONNECTED,
                    'last_synced_at' => now(),
                    'metadata' => ['demo' => true],
                ],
            );
            $isVideo = $provider === 'tiktok';
            $media[$provider] = ProfileIntegrationMedia::query()->updateOrCreate(
                ['profile_integration_id' => $integration->id, 'provider_media_id' => "insights-demo-{$provider}"],
                [
                    'profile_id' => $profile->id,
                    'provider' => $provider,
                    'media_type' => $isVideo ? 'VIDEO' : 'IMAGE',
                    'media_url' => $isVideo ? 'https://www.w3schools.com/html/mov_bbb.mp4' : "https://picsum.photos/seed/bigmelo-{$provider}/900/1100",
                    'thumbnail_url' => "https://picsum.photos/seed/bigmelo-{$provider}-thumb/720/900",
                    'permalink' => match ($provider) {
                        'instagram' => 'https://instagram.com/p/bigmelo-demo',
                        'tiktok' => 'https://www.tiktok.com/@bigmelo.demo/video/1',
                        default => 'https://onlyfans.com/bigmelo.demo',
                    },
                    'caption' => "Contenido demo de {$provider}",
                    'observation' => "Contenido local para validar eventos de {$provider}",
                    'age_restricted' => $provider === 'onlyfans',
                    'selected' => true,
                    'taken_at' => now()->subDays(3),
                    'metadata' => ['demo' => true],
                ],
            );
        }

        return [$products, $media];
    }

    private function removePreviousDemoData(Profile $profile): void
    {
        ProfileInteractionEvent::query()->where('profile_id', $profile->id)->where('idempotency_key', 'like', self::PREFIX.'%')->delete();
        $hashes = collect(range(1, 7))->map(fn (int $id): string => hash('sha256', self::PREFIX."visitor:{$id}"))->all();
        Chat::query()->where('profile_id', $profile->id)->whereIn('visitor_id_hash', $hashes)->delete();
    }

    /**
     * @return array<int, Chat>
     */
    private function conversations(Profile $profile): array
    {
        $categories = ConversationCategory::cases();
        $chats = [];

        foreach (range(1, 18) as $index) {
            $startedAt = now()->subDays(29 - $index)->setTime(9 + ($index % 8), ($index * 7) % 60);
            $visitor = hash('sha256', self::PREFIX.'visitor:'.(($index % 7) + 1));
            $chat = Chat::query()->create([
                'profile_id' => $profile->id,
                'status' => ChatStatus::Closed,
                'started_at' => $startedAt,
                'last_activity_at' => $startedAt->copy()->addMinutes(4),
                'ended_at' => $startedAt->copy()->addMinutes(34),
                'ended_reason' => ChatEndReason::Inactivity,
                'visitor_id_hash' => $visitor,
            ]);
            $chat->forceFill(['created_at' => $startedAt, 'updated_at' => $startedAt->copy()->addMinutes(34)])->saveQuietly();

            foreach (range(0, 3) as $messageIndex) {
                $question = $messageIndex % 2 === 0;
                $message = Message::query()->create([
                    'profile_id' => $profile->id,
                    'chat_id' => $chat->id,
                    'text' => $question
                        ? ($messageIndex === 0 ? 'Quiero conocer el perfil y sus productos.' : '¿Cómo puedo continuar?')
                        : ($messageIndex === 1 ? 'Claro, te comparto la información disponible.' : 'Puedes abrir el contenido o producto recomendado.'),
                    'type' => $question ? 'question' : 'answer',
                    'source' => $question ? 'api' : 'insights_demo',
                    'data' => ['demo' => true],
                ]);
                $at = $startedAt->copy()->addMinutes($messageIndex + 1);
                $message->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
            }

            $category = $categories[($index - 1) % count($categories)];
            ChatAnalysis::query()->create([
                'chat_id' => $chat->id,
                'profile_id' => $profile->id,
                'status' => $index === 18 ? ChatAnalysisStatus::NeedsReview : ChatAnalysisStatus::Completed,
                'primary_category' => $category,
                'secondary_categories' => [],
                'confidence' => $index === 18 ? 0.61 : 0.82 + (($index % 10) / 100),
                'summary' => "Conversación demo clasificada como {$category->value}.",
                'evidence_message_ids' => $chat->messages()->where('type', 'question')->pluck('id')->take(1)->all(),
                'model' => 'local-demo-classifier',
                'prompt_version' => 'v1',
                'taxonomy_version' => 'v1',
                'analyzed_at' => $chat->ended_at,
            ]);
            $chats[] = $chat;
        }

        return $chats;
    }

    /**
     * @param  array<int, Chat>  $chats
     * @param  array<string, ProfileProduct>  $products
     * @param  array<string, ProfileIntegrationMedia>  $media
     */
    private function interactions(Profile $profile, array $chats, array $products, array $media): void
    {
        foreach (range(1, 16) as $index) {
            $this->event($profile, $chats[$index % count($chats)], ProfileInsightEventType::ProfileViewed, "view:{$index}", [
                'visitor_id_hash' => hash('sha256', self::PREFIX.'visitor:'.(($index % 7) + 1)),
                'surface' => 'profile_page',
            ]);
        }

        $productShownCounts = ['external' => 18, 'whatsapp' => 10, 'historical' => 8];

        foreach ($productShownCounts as $key => $count) {
            $product = $products[$key];

            foreach (range(1, $count) as $index) {
                $this->event($profile, $chats[$index % count($chats)], ProfileInsightEventType::ProductShown, "product:{$key}:shown:{$index}", [
                    ...$this->productSnapshot($product),
                    'visitor_id_hash' => hash('sha256', self::PREFIX.'visitor:'.(($index % 7) + 1)),
                    'subject_type' => 'product',
                    'subject_id' => (string) $product->id,
                    'surface' => 'chat_answer',
                ]);
            }
        }

        $clickedProducts = [
            ...array_fill(0, 6, 'external'),
            ...array_fill(0, 3, 'whatsapp'),
            ...array_fill(0, 2, 'historical'),
        ];

        foreach ($clickedProducts as $offset => $key) {
            $index = $offset + 1;
            $product = $products[$key];
            $this->event($profile, $chats[$index % count($chats)], ProfileInsightEventType::ProductClicked, "product:{$index}", [
                ...$this->productSnapshot($product),
                'visitor_id_hash' => hash('sha256', self::PREFIX.'visitor:'.(($index % 7) + 1)),
                'subject_type' => 'product',
                'subject_id' => (string) $product->id,
                'surface' => $index % 2 ? 'product_button' : 'product_image',
                'metadata' => ['destination_type' => $product->destination_type->value],
            ]);
        }

        $providerCounts = [
            'instagram' => ['shown' => 24, 'opened' => 12, 'clicked' => 8],
            'tiktok' => ['shown' => 18, 'opened' => 9, 'clicked' => 5],
            'onlyfans' => ['shown' => 12, 'opened' => 6, 'clicked' => 3],
        ];

        foreach ($providerCounts as $provider => $counts) {
            foreach (range(1, $counts['shown']) as $index) {
                $this->event($profile, $chats[$index % count($chats)], ProfileInsightEventType::MediaShown, "{$provider}:shown:{$index}", [
                    'subject_type' => 'media',
                    'subject_id' => (string) $media[$provider]->id,
                    'provider' => $provider,
                    'surface' => 'chat_answer',
                    'media_type' => $provider === 'tiktok' ? 'video' : 'image',
                ]);
            }
            foreach (range(1, $counts['opened']) as $index) {
                $this->event($profile, $chats[$index % count($chats)], ProfileInsightEventType::MediaOpened, "{$provider}:opened:{$index}", [
                    'subject_type' => 'media', 'subject_id' => (string) $media[$provider]->id, 'provider' => $provider,
                    'surface' => 'chat_media_card', 'media_type' => $provider === 'tiktok' ? 'video' : 'image',
                ]);
            }
            foreach (range(1, $counts['clicked']) as $index) {
                $this->event($profile, $chats[$index % count($chats)], ProfileInsightEventType::MediaExternalClicked, "{$provider}:clicked:{$index}", [
                    'subject_type' => 'media', 'subject_id' => (string) $media[$provider]->id, 'provider' => $provider,
                    'surface' => $index % 2 ? 'chat_media_card' : 'chat_media_modal', 'media_type' => $provider === 'tiktok' ? 'video' : 'image',
                ]);
            }
        }

        foreach (array_keys((array) $profile->networks) as $index => $provider) {
            $this->event($profile, $chats[$index % count($chats)], ProfileInsightEventType::SocialLinkClicked, "social:{$provider}", [
                'subject_type' => 'social_network', 'provider' => strtolower($provider), 'surface' => 'profile_social_nav',
            ]);
        }
    }

    private function event(Profile $profile, Chat $chat, ProfileInsightEventType $type, string $key, array $attributes): void
    {
        $occurredAt = Carbon::parse($chat->started_at)->addMinutes(2);
        ProfileInteractionEvent::query()->create(array_merge([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'event_type' => $type,
            'occurred_at' => $occurredAt,
            'idempotency_key' => self::PREFIX.$key,
        ], $attributes));
    }

    /**
     * @return array<string, string>
     */
    private function productSnapshot(ProfileProduct $product): array
    {
        return [
            'subject_public_id' => $product->public_id,
            'subject_name' => $product->name,
            'subject_status' => $product->status->value,
            'subject_image_url' => $product->image_url,
            'destination_type' => $product->destination_type->value,
        ];
    }
}
