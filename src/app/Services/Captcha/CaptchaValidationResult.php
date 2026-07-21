<?php

namespace App\Services\Captcha;

class CaptchaValidationResult
{
    /**
     * @param  array<int, string>  $errorCodes
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $errorCodes = [],
    ) {}

    public static function success(): self
    {
        return new self(true);
    }

    /**
     * @param  array<int, string>  $errorCodes
     */
    public static function failure(array $errorCodes = []): self
    {
        return new self(false, $errorCodes);
    }
}
