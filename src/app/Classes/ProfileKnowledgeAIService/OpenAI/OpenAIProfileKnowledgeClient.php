<?php

namespace App\Classes\ProfileKnowledgeAIService\OpenAI;

use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeAIClient;
use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeStructure;
use App\Models\Profile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIProfileKnowledgeClient implements ProfileKnowledgeAIClient
{
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $baseUrl = null,
        private readonly ?string $defaultModel = null,
        private readonly ?int $maxTokens = null,
        private readonly ?float $temperature = null,
    ) {}

    public function structureCv(Profile $profile, string $text): ProfileKnowledgeStructure
    {
        if (! filled($this->apiKey ?: config('services.openai.api_key'))) {
            return new ProfileKnowledgeStructure(
                source: 'openai',
                status: 'failed',
                response: ['error' => 'OpenAI API key is not configured.']
            );
        }

        $baseUrl = rtrim($this->baseUrl ?: 'https://api.openai.com/v1', '/');
        $requestUrl = $baseUrl.'/chat/completions';

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.($this->apiKey ?: config('services.openai.api_key')),
                'Content-Type' => 'application/json',
            ])->post($requestUrl, [
                'model' => $this->defaultModel ?: 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $this->systemPrompt(),
                    ],
                    [
                        'role' => 'user',
                        'content' => $this->userPrompt($profile, $text),
                    ],
                ],
                'max_tokens' => $this->maxTokens ?: 3500,
                'temperature' => $this->temperature ?? 0.1,
                'response_format' => ['type' => 'json_object'],
            ]);

            $responseData = $response->json() ?: [];
            $content = $responseData['choices'][0]['message']['content'] ?? null;

            if ($response->successful() && is_string($content) && $content !== '') {
                $data = json_decode($content, true);

                if (is_array($data)) {
                    return new ProfileKnowledgeStructure(
                        source: 'openai',
                        status: 'success',
                        data: $data,
                        response: $responseData,
                        requestUrl: $requestUrl,
                        confidence: $this->confidence($responseData)
                    );
                }
            }

            Log::error('OpenAI profile knowledge API error', [
                'status' => $response->status(),
                'response' => $responseData,
                'request_url' => $requestUrl,
                'profile_id' => $profile->id,
            ]);

            return new ProfileKnowledgeStructure(
                source: 'openai',
                status: 'failed',
                response: $responseData,
                requestUrl: $requestUrl
            );
        } catch (\Throwable $e) {
            Log::error('OpenAI profile knowledge API exception', [
                'message' => $e->getMessage(),
                'request_url' => $requestUrl,
                'profile_id' => $profile->id,
            ]);

            return new ProfileKnowledgeStructure(
                source: 'openai',
                status: 'error',
                response: ['error' => $e->getMessage()],
                requestUrl: $requestUrl
            );
        }
    }

    private function systemPrompt(): string
    {
        return implode(' ', [
            'You structure CV/resume content for a profile knowledge base.',
            'Return only valid JSON.',
            'Do not invent companies, dates, projects, skills, education, awards, links, or locations.',
            'Split work experience into separate jobs when the CV lists multiple employers or roles.',
            'Respect section boundaries in English and Spanish; never place Skills/Habilidades, Education/Educacion, or Projects/Proyectos headings as project or work items.',
            'If a section is only a list of technologies, return those values under skills, not projects.',
            'If a work item description contains another company heading, split it into another work item.',
            'Use this JSON shape: {"summary":"","work":[{"company":"","role":"","start_date":"","end_date":"","date_range":"","duration":"","location":"","description":"","highlights":[],"technologies":[]}],"projects":[{"name":"","description":"","url":"","technologies":[],"source_work":""}],"skills":[{"name":"","category":"","description":""}],"education":[{"institution":"","degree":"","start_date":"","end_date":"","description":""}],"achievements":[{"name":"","description":"","date":""}]}.',
            'Use empty strings or empty arrays when a value is not present.',
        ]);
    }

    private function userPrompt(Profile $profile, string $text): string
    {
        return implode("\n\n", [
            'CV text:',
            mb_substr($text, 0, 24000),
        ]);
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function confidence(array $responseData): ?float
    {
        $finishReason = $responseData['choices'][0]['finish_reason'] ?? null;

        return $finishReason === 'stop' ? 0.9 : null;
    }
}
