<?php

namespace App\Classes\ChatAIService;

class ChatAIAnswer
{
    /**
     * The source AI provider.
     *
     * @var string
     */
    public $source;

    /**
     * The AI-generated answer text.
     *
     * @var string
     */
    public $answer;

    /**
     * The status of the answer generation.
     *
     * @var string
     */
    public $status;

    /**
     * The request URL used for the AI call.
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
     * The confidence score of the answer (0-1).
     *
     * @var float|null
     */
    public $confidence;

    /**
     * Create a new ChatAIAnswer instance.
     *
     * @param string $source
     * @param string $answer
     * @param string $status
     * @param array $response
     * @param string|null $requestUrl
     * @param float|null $confidence
     */
    public function __construct(
        string $source,
        string $answer = '',
        string $status = 'pending',
        array $response = [],
        ?string $requestUrl = null,
        ?float $confidence = null
    ) {
        $this->source = $source;
        $this->answer = $answer;
        $this->status = $status;
        $this->response = $response;
        $this->requestUrl = $requestUrl;
        $this->confidence = $confidence;
    }

    /**
     * Check if the answer generation was successful.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return in_array($this->status, ['completed', 'success']);
    }

    /**
     * Check if the answer generation failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return in_array($this->status, ['failed', 'error']);
    }

    /**
     * Check if the answer generation is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'processing']);
    }

    /**
     * Check if the answer has content.
     *
     * @return bool
     */
    public function hasAnswer(): bool
    {
        return !empty($this->answer);
    }

    /**
     * Convert the answer to an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'answer' => $this->answer,
            'status' => $this->status,
            'request_url' => $this->requestUrl,
            'response' => $this->response,
            'confidence' => $this->confidence,
        ];
    }
}
