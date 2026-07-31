<?php

namespace App\Exceptions\Subscriptions;

use RuntimeException;

class PaymentMethodException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode = 'PAYMENT_METHOD_INVALID',
        private readonly int $statusCode = 422,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
