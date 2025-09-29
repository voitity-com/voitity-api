<?php

namespace App\Classes\VoiceService;

class VoiceClientAddedSample
{
    /**
     * The source provider for the voice sample addition.
     *
     * @var string
     */
    public $source;

    /**
     * The status of the sample addition operation.
     *
     * @var string
     */
    public $status;

    /**
     * The request URL used for the sample addition operation.
     *
     * @var string|null
     */
    public $requestUrl;

    /**
     * The response data from the sample addition operation.
     *
     * @var array|null
     */
    public $response;

    /**
     * Create a new VoiceClientAddedSample instance.
     *
     * @param string $source
     * @param string $status
     * @param array $response The response data
     * @param string|null $requestUrl
     */
    public function __construct(
        string $source,
        string $status = 'pending',
        array $response = [],
        ?string $requestUrl = null
    ) {
        $this->source = $source;
        $this->status = $status;
        $this->response = $response;
        $this->requestUrl = $requestUrl;
    }

    /**
     * Check if the sample addition was successful.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return in_array($this->status, ['completed', 'success']);
    }

    /**
     * Check if the sample addition failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return in_array($this->status, ['failed', 'error']);
    }

    /**
     * Check if the sample addition is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'processing']);
    }

    /**
     * Convert the added sample result to an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'status' => $this->status,
            'request_url' => $this->requestUrl,
            'response' => $this->response,
        ];
    }
}
