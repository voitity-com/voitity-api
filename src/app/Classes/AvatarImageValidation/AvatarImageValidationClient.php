<?php

namespace App\Classes\AvatarImageValidation;

interface AvatarImageValidationClient
{
    public function analyze(string $imageBytes): AvatarImageAnalysis;

    public function name(): string;
}
