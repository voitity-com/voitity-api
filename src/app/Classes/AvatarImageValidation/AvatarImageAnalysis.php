<?php

namespace App\Classes\AvatarImageValidation;

final readonly class AvatarImageAnalysis
{
    /**
     * @param  array<int, array<string, mixed>>  $faces
     */
    public function __construct(
        public array $faces,
        public ?string $requestId = null,
    ) {}
}
