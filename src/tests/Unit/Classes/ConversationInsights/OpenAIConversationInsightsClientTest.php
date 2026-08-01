<?php

namespace Tests\Unit\Classes\ConversationInsights;

use App\Classes\ConversationInsights\ConversationInsightsClient;
use App\Enums\ConversationCategory;
use App\Models\Chat;
use App\Models\Message;
use App\Models\Profile;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIConversationInsightsClientTest extends TestCase
{
    public function test_it_maps_strict_structured_classification_without_real_provider_call(): void
    {
        config([
            'insights.classification.driver' => 'openai',
            'insights.classification.model' => 'gpt-4o-mini',
            'services.openai.api_key' => 'test-key',
            'chatai.drivers.openai.base_url' => 'https://api.openai.test/v1',
        ]);
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'primary_category' => 'purchase_intent',
                            'secondary_categories' => ['product_interest'],
                            'confidence' => 0.94,
                            'summary' => 'Visitor asks how to buy.',
                            'evidence_message_ids' => [1],
                        ]),
                    ],
                ]],
            ]),
        ]);
        $profile = Profile::factory()->create();
        $chat = Chat::query()->create(['profile_id' => $profile->id]);
        $message = Message::query()->create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => '¿Cómo lo compro?',
            'type' => 'question',
            'source' => 'api',
        ]);

        $classification = app(ConversationInsightsClient::class)->classify($chat);

        $this->assertSame(ConversationCategory::PurchaseIntent, $classification->primaryCategory);
        $this->assertSame(0.94, $classification->confidence);
        $this->assertSame([$message->id], $classification->evidenceMessageIds);
        Http::assertSent(fn (Request $request): bool => $request['response_format']['type'] === 'json_schema'
            && $request['response_format']['json_schema']['strict'] === true);
    }
}
