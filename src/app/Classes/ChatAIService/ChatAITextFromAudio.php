<?php

namespace App\Classes\ChatAIService;

class ChatAITextFromAudio
{
    /**
     * The source AI provider.
     *
     * @var string
     */
    public $source;

    /**
     * The extracted text from audio.
     *
     * @var string
     */
    public $text;

    /**
     * The status of the text extraction.
     *
     * @var string
     */
    public $status;

    /**
     * The original audio file path.
     *
     * @var string
     */
    public $audioPath;

    /**
     * The request URL used for the transcription call.
     *
     * @var string|null
     */
    public $requestUrl;

    /**
     * The response data from the AI provider.
     *
     * @var array|null
     */
    public $response;

    /**
     * The confidence score of the transcription (0-1).
     *
     * @var float|null
     */
    public $confidence;

    /**
     * The detected language of the audio.
     *
     * @var string|null
     */
    public $detectedLanguage;

    /**
     * The duration of the audio in seconds.
     *
     * @var float|null
     */
    public $duration;

    /**
     * Create a new ChatAITextFromAudio instance.
     *
     * @param string $source
     * @param string $audioPath
     * @param string $text
     * @param string $status
     * @param array $response
     * @param string|null $requestUrl
     * @param float|null $confidence
     * @param string|null $detectedLanguage
     * @param float|null $duration
     */
    public function __construct(
        string $source,
        string $audioPath,
        string $text = '',
        string $status = 'pending',
        array $response = [],
        ?string $requestUrl = null,
        ?float $confidence = null,
        ?string $detectedLanguage = null,
        ?float $duration = null
    ) {
        $this->source = $source;
        $this->audioPath = $audioPath;
        $this->text = $text;
        $this->status = $status;
        $this->response = $response;
        $this->requestUrl = $requestUrl;
        $this->confidence = $confidence;
        $this->detectedLanguage = $detectedLanguage;
        $this->duration = $duration;
    }

    /**
     * Check if the text extraction was successful.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return in_array($this->status, ['completed', 'success']);
    }

    /**
     * Check if the text extraction failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return in_array($this->status, ['failed', 'error']);
    }

    /**
     * Check if the text extraction is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'processing']);
    }

    /**
     * Check if the transcription has text content.
     *
     * @return bool
     */
    public function hasText(): bool
    {
        return !empty($this->text);
    }

    /**
     * Get the word count of the extracted text.
     *
     * @return int
     */
    public function getWordCount(): int
    {
        return str_word_count($this->text);
    }

    /**
     * Convert the text extraction result to an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'audio_path' => $this->audioPath,
            'text' => $this->text,
            'status' => $this->status,
            'request_url' => $this->requestUrl,
            'response' => $this->response,
            'confidence' => $this->confidence,
            'detected_language' => $this->detectedLanguage,
            'duration' => $this->duration,
            'word_count' => $this->getWordCount(),
        ];
    }
}
