<?php

namespace App\Exceptions\Subscriptions;

use RuntimeException;

class SubscriptionEntitlementException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        string $message,
        private readonly array $errors = [],
        private readonly int $statusCode = 402
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
