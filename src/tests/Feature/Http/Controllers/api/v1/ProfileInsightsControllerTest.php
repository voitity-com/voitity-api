<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Enums\ChatAnalysisStatus;
use App\Enums\ChatStatus;
use App\Enums\ConversationCategory;
use App\Enums\ProfileInsightEventType;
use App\Enums\ProfileProductDestinationType;
use App\Enums\ProfileProductStatus;
use App\Models\Chat;
use App\Models\ChatAnalysis;
use App\Models\FeatureFlag;
use App\Models\Message;
use App\Models\Profile;
use App\Models\ProfileFeatureSetting;
use App\Models\ProfileInteractionEvent;
use App\Models\ProfileProduct;
use App\Models\User;
use Illuminate\Support\Str;

class ProfileInsightsControllerTest extends TestAPI
{
    public function test_owner_can_reconcile_chat_message_visitor_and_provider_metrics(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create();
        $chat = Chat::query()->create([
            'profile_id' => $profile->id,
            'status' => ChatStatus::Closed,
            'started_at' => now(),
            'last_activity_at' => now(),
            'ended_at' => now(),
        ]);
        Message::query()->create(['profile_id' => $profile->id, 'chat_id' => $chat->id, 'text' => 'Question', 'type' => 'question', 'source' => 'api']);
        Message::query()->create(['profile_id' => $profile->id, 'chat_id' => $chat->id, 'text' => 'Answer', 'type' => 'answer', 'source' => 'openai']);
        ChatAnalysis::query()->create([
            'chat_id' => $chat->id,
            'profile_id' => $profile->id,
            'status' => ChatAnalysisStatus::Completed,
            'primary_category' => ConversationCategory::PurchaseIntent,
            'confidence' => 0.9,
            'analyzed_at' => now(),
        ]);
        $this->event($profile, $chat, ProfileInsightEventType::ProfileViewed, 'view-1', ['visitor_id_hash' => 'visitor-a']);
        $this->event($profile, $chat, ProfileInsightEventType::ProfileViewed, 'view-2', ['visitor_id_hash' => 'visitor-a']);
        $this->event($profile, $chat, ProfileInsightEventType::MediaShown, 'ig-shown', ['provider' => 'instagram', 'media_type' => 'image']);
        $this->event($profile, $chat, ProfileInsightEventType::MediaExternalClicked, 'ig-click', ['provider' => 'instagram', 'media_type' => 'image']);
        $this->event($profile, $chat, ProfileInsightEventType::MediaShown, 'yt-shown', ['provider' => 'youtube', 'media_type' => 'video']);
        $this->event($profile, $chat, ProfileInsightEventType::MediaOpened, 'yt-opened', ['provider' => 'youtube', 'media_type' => 'video']);
        $this->event($profile, $chat, ProfileInsightEventType::MediaExternalClicked, 'yt-video-click', [
            'destination_type' => 'provider_video',
            'provider' => 'youtube',
            'media_type' => 'video',
        ]);
        $this->event($profile, $chat, ProfileInsightEventType::MediaExternalClicked, 'yt-channel-click', [
            'destination_type' => 'provider_channel',
            'provider' => 'youtube',
            'media_type' => 'video',
        ]);
        $this->event($profile, $chat, ProfileInsightEventType::ProductClicked, 'product-click');
        $token = $user->createToken('insights', ['insights:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/insights?from=".now()->toDateString().'&to='.now()->toDateString().'&timezone=UTC')
            ->assertOk()
            ->assertJsonPath('data.summary.new_chats', 1)
            ->assertJsonPath('data.summary.total_messages', 2)
            ->assertJsonPath('data.summary.visitor_messages', 1)
            ->assertJsonPath('data.summary.profile_answers', 1)
            ->assertJsonPath('data.summary.unique_visitors', 1)
            ->assertJsonPath('data.summary.product_clicks', 1)
            ->assertJsonPath('data.summary.instagram_shown', 1)
            ->assertJsonPath('data.summary.instagram_external_clicks', 1)
            ->assertJsonPath('data.summary.youtube_shown', 1)
            ->assertJsonPath('data.summary.youtube_opened', 1)
            ->assertJsonPath('data.summary.youtube_external_clicks', 2)
            ->assertJsonPath('data.summary.youtube_video_clicks', 1)
            ->assertJsonPath('data.summary.youtube_channel_clicks', 1)
            ->assertJsonPath('data.provider_funnel.0.ctr', 100)
            ->assertJsonPath('data.provider_funnel.3.provider', 'youtube')
            ->assertJsonPath('data.provider_funnel.3.video_clicks', 1)
            ->assertJsonPath('data.provider_funnel.3.channel_clicks', 1)
            ->assertJsonPath('data.analysis_coverage.classified', 1);
    }

    public function test_missing_ability_cannot_view_profile_insights(): void
    {
        $owner = User::factory()->create();
        $profile = Profile::factory()->for($owner)->create();

        $this->withToken($owner->createToken('owner', ['profile:read'])->plainTextToken)
            ->getJson("/api/profile/{$profile->id}/insights")
            ->assertForbidden();
    }

    public function test_non_owner_cannot_view_profile_insights(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $profile = Profile::factory()->for($owner)->create();

        $this->withToken($other->createToken('other', ['insights:read'])->plainTextToken)
            ->getJson("/api/profile/{$profile->id}/insights")
            ->assertNotFound();
    }

    public function test_chat_report_calculates_goals_duration_messages_and_product_action_rate(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create();
        $chat = Chat::query()->create([
            'profile_id' => $profile->id,
            'status' => ChatStatus::Closed,
            'started_at' => now()->subMinutes(40),
            'last_activity_at' => now()->subMinutes(35),
            'ended_at' => now()->subMinutes(10),
        ]);
        Message::query()->create(['profile_id' => $profile->id, 'chat_id' => $chat->id, 'text' => 'Question', 'type' => 'question', 'source' => 'api']);
        Message::query()->create(['profile_id' => $profile->id, 'chat_id' => $chat->id, 'text' => 'Answer', 'type' => 'answer', 'source' => 'openai']);
        ChatAnalysis::query()->create([
            'chat_id' => $chat->id,
            'profile_id' => $profile->id,
            'status' => ChatAnalysisStatus::Completed,
            'primary_category' => ConversationCategory::PurchaseIntent,
            'confidence' => 0.9,
            'analyzed_at' => now(),
        ]);
        $this->event($profile, $chat, ProfileInsightEventType::ProductClicked, 'chat-product-click', [
            'subject_type' => 'product',
            'subject_id' => '1',
            'destination_type' => 'whatsapp',
        ]);

        $response = $this->withToken($user->createToken('insights', ['insights:read'])->plainTextToken)
            ->getJson("/api/profile/{$profile->id}/insights/chats?from=".now()->subDay()->toDateString().'&to='.now()->toDateString().'&timezone=UTC')
            ->assertOk()
            ->assertJsonPath('data.summary.total_chats', 1)
            ->assertJsonPath('data.summary.total_messages', 2)
            ->assertJsonPath('data.summary.average_messages_per_chat', 2)
            ->assertJsonPath('data.summary.average_duration_minutes', 30)
            ->assertJsonPath('data.analysis_coverage.classified', 1);

        $purchase = collect($response->json('data.goal_actions'))->firstWhere('key', ConversationCategory::PurchaseIntent->value);
        $this->assertSame(1, $purchase['product_click_chats']);
        $this->assertSame(1, $purchase['whatsapp_click_chats']);
        $this->assertSame(100, $purchase['product_click_rate']);
    }

    public function test_product_report_keeps_snapshot_after_product_is_deleted(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create(['products_enabled' => true]);
        FeatureFlag::query()->updateOrCreate(['key' => 'products'], ['name' => 'Products', 'enabled' => true]);
        ProfileFeatureSetting::query()->updateOrCreate(
            ['profile_id' => $profile->id, 'feature_key' => 'products'],
            ['enabled' => true]
        );
        $product = ProfileProduct::query()->create([
            'public_id' => (string) Str::uuid(),
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'slug' => 'historical-product',
            'name' => 'Historical product',
            'description' => 'Product used to prove historical analytics.',
            'image_source' => 'url',
            'image_url' => 'https://example.com/product.jpg',
            'destination_type' => ProfileProductDestinationType::ExternalUrl,
            'destination_url' => 'https://example.com/product',
            'status' => ProfileProductStatus::Published,
            'fingerprint' => hash('sha256', 'historical-product'),
            'published_at' => now(),
        ]);
        $chat = Chat::query()->create([
            'profile_id' => $profile->id,
            'status' => ChatStatus::Closed,
            'started_at' => now(),
            'last_activity_at' => now(),
            'ended_at' => now(),
        ]);
        $snapshot = [
            'subject_type' => 'product',
            'subject_id' => (string) $product->id,
            'subject_public_id' => $product->public_id,
            'subject_name' => $product->name,
            'subject_status' => $product->status->value,
            'subject_image_url' => $product->image_url,
            'destination_type' => $product->destination_type->value,
            'visitor_id_hash' => 'visitor-a',
        ];
        $this->event($profile, $chat, ProfileInsightEventType::ProductShown, 'historical-shown', $snapshot);
        $this->event($profile, $chat, ProfileInsightEventType::ProductClicked, 'historical-clicked', [
            ...$snapshot,
            'surface' => 'product_button',
        ]);
        $product->delete();

        $this->withToken($user->createToken('insights', ['insights:read'])->plainTextToken)
            ->getJson("/api/profile/{$profile->id}/insights/products?from=".now()->toDateString().'&to='.now()->toDateString().'&timezone=UTC')
            ->assertOk()
            ->assertJsonPath('data.available.available', true)
            ->assertJsonPath('data.available.mode', 'historical_only')
            ->assertJsonPath('data.products.0.name', 'Historical product')
            ->assertJsonPath('data.products.0.status', 'deleted')
            ->assertJsonPath('data.products.0.historical', true)
            ->assertJsonPath('data.products.0.shown', 1)
            ->assertJsonPath('data.products.0.clicks', 1)
            ->assertJsonPath('data.products.0.ctr', 100);
    }

    private function event(Profile $profile, Chat $chat, ProfileInsightEventType $type, string $key, array $extra = []): void
    {
        ProfileInteractionEvent::query()->create(array_merge([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'event_type' => $type,
            'occurred_at' => now(),
            'idempotency_key' => $key,
        ], $extra));
    }
}
