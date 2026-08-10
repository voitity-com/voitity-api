<?php

namespace App\Classes\AvatarImageValidation;

final readonly class AvatarImageValidationResult
{
    /**
     * @param  list<string>  $reasonCodes
     * @param  array<string, int|float|string|bool|null>  $summary
     */
    public function __construct(
        public bool $valid,
        public array $reasonCodes,
        public array $summary,
        public ?string $requestId = null,
    ) {}
}
