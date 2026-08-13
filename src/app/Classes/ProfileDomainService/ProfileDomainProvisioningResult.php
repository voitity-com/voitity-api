<?php

namespace App\Classes\ProfileDomainService;

use App\Enums\ProfileDomainStatus;

readonly class ProfileDomainProvisioningResult
{
    /**
     * @param  array<int, array{name: string, type: string, value: string, purpose: string}>  $dnsRecords
     */
    public function __construct(
        public ProfileDomainStatus $status,
        public ?string $tenantId = null,
        public ?string $tenantArn = null,
        public ?string $routingEndpoint = null,
        public ?string $certificateArn = null,
        public ?string $certificateStatus = null,
        public ?string $dnsStatus = null,
        public array $dnsRecords = [],
        public ?string $providerStatus = null,
    ) {}
}
