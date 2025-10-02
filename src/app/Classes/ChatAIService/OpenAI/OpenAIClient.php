<?php

namespace App\Classes\ChatAIService\OpenAI;

use App\Classes\ChatAIService\ChatAIClient;
use App\Classes\ChatAIService\ChatAIAnswer;
use App\Classes\ChatAIService\ChatAITextFromAudio;
use App\Models\Profile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIClient implements ChatAIClient
{
    /**
     * The OpenAI API key.
     *
     * @var string
     */
    private $apiKey;

    /**
     * The OpenAI API base URL.
     *
     * @var string
     */
    private $baseUrl;

    /**
     * The default model for chat completions.
     *
     * @var string
     */
    private $defaultModel;

    /**
     * The default model for audio transcriptions.
     *
     * @var string
     */
    private $whisperModel;

    /**
     * Create a new OpenAIClient instance.
     *
     * @param string|null $apiKey
     * @param string|null $baseUrl
     * @param string|null $defaultModel
     * @param string|null $whisperModel
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $defaultModel = null,
        ?string $whisperModel = null
    ) {
        $this->apiKey = $apiKey ?: config('services.openai.api_key');
        $this->baseUrl = $baseUrl ?: 'https://api.openai.com/v1';
        $this->defaultModel = $defaultModel ?: 'gpt-4';
        $this->whisperModel = $whisperModel ?: 'whisper-1';
    }

    /**
     * Get an AI answer based on a profile and message.
     *
     * @param Profile $profile The user profile for context
     * @param string $message The message to get an answer for
     * @return ChatAIAnswer The AI answer response
     */
    public function getAnswer(Profile $profile, string $message): ChatAIAnswer
    {
        $requestUrl = $this->baseUrl . '/chat/completions';
        
        try {
            // Build the system prompt based on profile data
            $systemPrompt = $this->buildSystemPrompt($profile);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($requestUrl, [
                'model' => $this->defaultModel,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt
                    ],
                    [
                        'role' => 'user',
                        'content' => $message
                    ]
                ],
                'max_tokens' => 1000,
                'temperature' => 0.7,
            ]);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['choices'][0]['message']['content'])) {
                $answer = $responseData['choices'][0]['message']['content'];
                $confidence = $this->calculateConfidence($responseData);
                
                return new ChatAIAnswer(
                    source: 'openai',
                    answer: $answer,
                    status: 'success',
                    requestUrl: $requestUrl,
                    response: $responseData,
                    confidence: $confidence
                );
            } else {
                Log::error('OpenAI API error', [
                    'status' => $response->status(),
                    'response' => $responseData,
                    'request_url' => $requestUrl
                ]);

                return new ChatAIAnswer(
                    source: 'openai',
                    answer: '',
                    status: 'failed',
                    requestUrl: $requestUrl,
                    response: $responseData
                );
            }
        } catch (\Exception $e) {
            Log::error('OpenAI API exception', [
                'message' => $e->getMessage(),
                'request_url' => $requestUrl
            ]);

            return new ChatAIAnswer(
                source: 'openai',
                answer: '',
                status: 'error',
                requestUrl: $requestUrl,
                response: ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Convert audio to text based on audio file path.
     *
     * @param string $audioPath The path to the audio file
     * @return ChatAITextFromAudio The text extracted from audio
     */
    public function getTextFromAudio(string $audioPath): ChatAITextFromAudio
    {
        $requestUrl = $this->baseUrl . '/audio/transcriptions';
        
        try {
            if (!file_exists($audioPath)) {
                return new ChatAITextFromAudio(
                    source: 'openai',
                    audioPath: $audioPath,
                    text: '',
                    status: 'failed',
                    response: ['error' => 'Audio file not found']
                );
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->attach(
                'file',
                file_get_contents($audioPath),
                basename($audioPath)
            )->post($requestUrl, [
                'model' => $this->whisperModel,
                'response_format' => 'verbose_json',
            ]);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['text'])) {
                $text = $responseData['text'];
                $confidence = $this->calculateTranscriptionConfidence($responseData);
                $detectedLanguage = $responseData['language'] ?? null;
                $duration = $responseData['duration'] ?? null;
                
                return new ChatAITextFromAudio(
                    source: 'openai',
                    audioPath: $audioPath,
                    text: $text,
                    status: 'success',
                    response: $responseData,
                    requestUrl: $requestUrl,
                    confidence: $confidence,
                    detectedLanguage: $detectedLanguage,
                    duration: $duration
                );
            } else {
                Log::error('OpenAI Whisper API error', [
                    'status' => $response->status(),
                    'response' => $responseData,
                    'request_url' => $requestUrl,
                    'audio_path' => $audioPath
                ]);

                return new ChatAITextFromAudio(
                    source: 'openai',
                    audioPath: $audioPath,
                    text: '',
                    status: 'failed',
                    response: $responseData,
                    requestUrl: $requestUrl
                );
            }
        } catch (\Exception $e) {
            Log::error('OpenAI Whisper API exception', [
                'message' => $e->getMessage(),
                'request_url' => $requestUrl,
                'audio_path' => $audioPath
            ]);

            return new ChatAITextFromAudio(
                source: 'openai',
                audioPath: $audioPath,
                text: '',
                status: 'error',
                response: ['error' => $e->getMessage()],
                requestUrl: $requestUrl
            );
        }
    }

    /**
     * Build a system prompt based on profile data.
     *
     * @param Profile $profile
     * @return string
     */
    private function buildSystemPrompt(Profile $profile): string
    {
        $prompt = "You are an AI assistant";
        
        // Add name if available
        if ($profile->name) {
            $prompt .= " named {$profile->name}";
        }
        
        // Add description/role context
        if ($profile->description) {
            $prompt .= ". Your role is: {$profile->description}";
        }
        
        // Add genre context
        if ($profile->genre) {
            $prompt .= " You operate in the {$profile->genre} domain";
        }
        
        // Add personality traits
        if ($profile->personality) {
            $prompt .= ". Your personality is {$profile->personality}";
        }
        
        // Add specific instructions based on description
        if ($profile->description) {
            $description = strtolower($profile->description);
            
            if (str_contains($description, 'lawyer') || str_contains($description, 'legal')) {
                $prompt .= ". Provide legal advice and information, but always remind users to consult with a qualified attorney for specific legal matters";
            } elseif (str_contains($description, 'doctor') || str_contains($description, 'medical')) {
                $prompt .= ". Provide medical information and guidance, but always remind users to consult with healthcare professionals for medical decisions";
            } elseif (str_contains($description, 'teacher') || str_contains($description, 'educator')) {
                $prompt .= ". Be educational and patient, breaking down complex topics into understandable explanations";
            } elseif (str_contains($description, 'coach') || str_contains($description, 'trainer')) {
                $prompt .= ". Be motivational and supportive, helping users achieve their goals";
            }
        }
        
        // Add personality-specific tone instructions
        if ($profile->personality) {
            $personality = strtolower($profile->personality);
            
            if (str_contains($personality, 'friendly')) {
                $prompt .= ". Always maintain a warm, approachable tone";
            } elseif (str_contains($personality, 'professional')) {
                $prompt .= ". Maintain a professional and formal tone";
            } elseif (str_contains($personality, 'casual')) {
                $prompt .= ". Use a casual, conversational tone";
            } elseif (str_contains($personality, 'funny') || str_contains($personality, 'humorous')) {
                $prompt .= ". Include appropriate humor when suitable";
            }
        }
        
        $prompt .= ". Always respond in character and maintain consistency with your defined role and personality.";
        
        return $prompt;
    }

    /**
     * Calculate confidence score from OpenAI response.
     *
     * @param array $responseData
     * @return float|null
     */
    private function calculateConfidence(array $responseData): ?float
    {
        // OpenAI doesn't provide direct confidence scores for chat completions
        // We can estimate based on usage tokens and finish reason
        if (!isset($responseData['usage']) || !isset($responseData['choices'][0]['finish_reason'])) {
            return null;
        }
        
        $finishReason = $responseData['choices'][0]['finish_reason'];
        $usage = $responseData['usage'];
        
        // Base confidence on finish reason
        $confidence = match ($finishReason) {
            'stop' => 0.9,        // Natural completion
            'length' => 0.7,      // Hit token limit
            'content_filter' => 0.3, // Content filtered
            default => 0.5
        };
        
        // Adjust based on token usage efficiency
        if (isset($usage['completion_tokens']) && isset($usage['prompt_tokens'])) {
            $responseRatio = $usage['completion_tokens'] / ($usage['prompt_tokens'] + $usage['completion_tokens']);
            // Prefer responses that are neither too short nor too long relative to prompt
            if ($responseRatio > 0.1 && $responseRatio < 0.8) {
                $confidence += 0.1;
            }
        }
        
        return min(1.0, $confidence);
    }

    /**
     * Calculate confidence score from Whisper transcription response.
     *
     * @param array $responseData
     * @return float|null
     */
    private function calculateTranscriptionConfidence(array $responseData): ?float
    {
        // Whisper doesn't provide direct confidence scores in the API
        // We can estimate based on available metadata
        if (!isset($responseData['text'])) {
            return null;
        }
        
        $text = $responseData['text'];
        $baseConfidence = 0.8; // Default confidence for successful transcription
        
        // Adjust based on text characteristics
        if (strlen($text) < 10) {
            $baseConfidence -= 0.2; // Very short text might be less reliable
        }
        
        // Check for common transcription artifacts that might indicate lower quality
        $artifacts = ['[inaudible]', '[unclear]', '***', '...'];
        foreach ($artifacts as $artifact) {
            if (str_contains(strtolower($text), $artifact)) {
                $baseConfidence -= 0.1;
            }
        }
        
        return max(0.1, min(1.0, $baseConfidence));
    }
}
