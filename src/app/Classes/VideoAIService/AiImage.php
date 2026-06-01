<?php

namespace App\Classes\VideoAIService;

class AiImage
{
    public function __construct(
        public ?string $id = null,
        public ?string $createdAt = null,
        public string $status = 'pending',
        public array $output = [],
        public array $response = [],
        public ?string $requestUrl = null
    ) {
        //
    }

    public function isSuccessful(): bool
    {
        return in_array(strtolower($this->status), ['succeeded', 'completed', 'success'], true);
    }

    public function isFailed(): bool
    {
        return in_array(strtolower($this->status), ['failed', 'error'], true);
    }

    public function isPending(): bool
    {
        return in_array(strtolower($this->status), ['creating', 'pending', 'processing', 'running', 'queued', 'throttled'], true);
    }

    public function getOutputUrl(): ?string
    {
        return $this->output[0] ?? null;
    }

    public function getResponse(): array
    {
        return $this->response;
    }

    public function getRequestUrl(): ?string
    {
        return $this->requestUrl;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'createdAt' => $this->createdAt,
            'status' => $this->status,
            'output' => $this->output,
        ];
    }
}
