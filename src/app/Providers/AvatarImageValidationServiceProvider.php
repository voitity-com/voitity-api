<?php

namespace App\Providers;

use App\Classes\AvatarImageValidation\AvatarImageValidationClient;
use App\Classes\AvatarImageValidation\Rekognition\RekognitionAvatarImageValidationClient;
use Aws\Rekognition\RekognitionClient;
use Illuminate\Support\ServiceProvider;

class AvatarImageValidationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RekognitionClient::class, fn (): RekognitionClient => new RekognitionClient([
            'region' => (string) config('avatar-image-validation.rekognition.region', 'us-east-1'),
            'version' => 'latest',
        ]));

        $this->app->singleton(
            AvatarImageValidationClient::class,
            RekognitionAvatarImageValidationClient::class,
        );
    }
}
