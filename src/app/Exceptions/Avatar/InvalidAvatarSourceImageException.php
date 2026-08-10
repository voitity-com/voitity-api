<?php

namespace App\Exceptions\Avatar;

use App\Classes\AvatarImageValidation\AvatarImageValidationResult;
use RuntimeException;

class InvalidAvatarSourceImageException extends RuntimeException
{
    public function __construct(
        private readonly AvatarImageValidationResult $validationResult,
        private readonly string $locale = 'es',
    ) {
        parent::__construct($this->messages()[0] ?? $this->fallbackMessage());
    }

    public function validationResult(): AvatarImageValidationResult
    {
        return $this->validationResult;
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        $copy = config('avatar-image-validation.copy.'.$this->normalizedLocale(), []);

        return array_values(array_unique(array_map(
            fn (string $reason): string => (string) ($copy[$reason] ?? $this->fallbackMessage()),
            $this->validationResult->reasonCodes,
        )));
    }

    private function normalizedLocale(): string
    {
        return str_starts_with(strtolower($this->locale), 'en') ? 'en' : 'es';
    }

    private function fallbackMessage(): string
    {
        return (string) config(
            'avatar-image-validation.copy.'.$this->normalizedLocale().'.invalid_image',
            'La imagen no cumple los requisitos para generar el avatar.',
        );
    }
}
