<?php

namespace Tests\Unit\Classes\ProfileKnowledgeAIService\OpenAI;

use App\Classes\ProfileKnowledgeAIService\OpenAI\OpenAIProfileKnowledgeClient;
use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeStructure;
use App\Models\Profile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenAIProfileKnowledgeClientTest extends TestCase
{
    #[Test]
    public function it_maps_successful_openai_response_to_profile_structure(): void
    {
        Log::spy();

        Http::fake([
            'https://fake-openai.test/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'summary' => 'Developer profile',
                                'work' => [
                                    [
                                        'company' => 'Nu Image Medical',
                                        'role' => 'PHP Software Developer',
                                        'technologies' => ['Laravel'],
                                    ],
                                ],
                                'skills' => [
                                    ['name' => 'Laravel'],
                                ],
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);

        $client = new OpenAIProfileKnowledgeClient(
            apiKey: 'test-key',
            baseUrl: 'https://fake-openai.test/v1',
            defaultModel: 'gpt-test',
            maxTokens: 1200,
            temperature: 0.2
        );

        $result = $client->structureCv(new Profile(['profession_key' => 'developer']), 'Experience text');

        $this->assertInstanceOf(ProfileKnowledgeStructure::class, $result);
        $this->assertTrue($result->isSuccessful());
        $this->assertSame('openai', $result->source);
        $this->assertSame('Nu Image Medical', $result->items('work')[0]['company']);
        $this->assertSame(0.9, $result->confidence);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://fake-openai.test/v1/chat/completions'
                && $payload['model'] === 'gpt-test'
                && $payload['response_format'] === ['type' => 'json_object']
                && $payload['max_tokens'] === 1200
                && $payload['temperature'] === 0.2
                && str_contains($payload['messages'][0]['content'], 'Return only valid JSON')
                && str_contains($payload['messages'][1]['content'], 'Profile profession: developer');
        });
    }

    #[Test]
    public function it_returns_failed_result_when_api_key_is_missing(): void
    {
        $client = new OpenAIProfileKnowledgeClient(apiKey: null);

        config()->set('services.openai.api_key', null);

        $result = $client->structureCv(new Profile, 'CV text');

        $this->assertSame('failed', $result->status);
        $this->assertSame('OpenAI API key is not configured.', $result->response['error']);
    }
}
