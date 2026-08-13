<?php

namespace App\Classes\ProfileDomainService;

use App\Classes\ProfileDomainService\CloudFront\CloudFrontProfileDomainProvider;
use App\Classes\ProfileDomainService\Local\LocalProfileDomainProvider;
use Illuminate\Support\Manager;

class ProfileDomainManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) config('profile-domains.default', app()->environment(['local', 'testing']) ? 'local' : 'cloudfront');
    }

    protected function createLocalDriver(): ProfileDomainProvider
    {
        return $this->container->make(LocalProfileDomainProvider::class);
    }

    protected function createCloudfrontDriver(): ProfileDomainProvider
    {
        return $this->container->make(CloudFrontProfileDomainProvider::class);
    }
}
