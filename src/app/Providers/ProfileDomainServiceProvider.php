<?php

namespace App\Providers;

use App\Classes\ProfileDomainService\ProfileDomainManager;
use App\Classes\ProfileDomainService\ProfileDomainProvider;
use Aws\CloudFront\CloudFrontClient;
use Illuminate\Support\ServiceProvider;

class ProfileDomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CloudFrontClient::class, fn (): CloudFrontClient => new CloudFrontClient([
            'region' => (string) config('profile-domains.drivers.cloudfront.region', 'us-east-1'),
            'version' => 'latest',
        ]));
        $this->app->singleton(ProfileDomainManager::class, fn ($app) => new ProfileDomainManager($app));
        $this->app->bind(
            ProfileDomainProvider::class,
            fn ($app) => $app->make(ProfileDomainManager::class)->driver(),
        );
    }
}
