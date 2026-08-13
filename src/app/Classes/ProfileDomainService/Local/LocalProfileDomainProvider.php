<?php

namespace App\Classes\ProfileDomainService\Local;

use App\Classes\ProfileDomainService\ProfileDomainProvider;
use App\Classes\ProfileDomainService\ProfileDomainProvisioningResult;
use App\Enums\ProfileDomainStatus;
use App\Models\ProfileDomain;

class LocalProfileDomainProvider implements ProfileDomainProvider
{
    public function provision(ProfileDomain $domain): ProfileDomainProvisioningResult
    {
        $endpoint = (string) config('profile-domains.drivers.local.routing_endpoint', 'profiles.localhost');

        return new ProfileDomainProvisioningResult(
            status: ProfileDomainStatus::PendingDns,
            tenantId: "local-profile-{$domain->profile_id}",
            routingEndpoint: $endpoint,
            certificateStatus: 'local',
            dnsStatus: 'pending',
            dnsRecords: [$this->dnsRecord($domain, $endpoint)],
            providerStatus: 'local-ready',
        );
    }

    public function refresh(ProfileDomain $domain): ProfileDomainProvisioningResult
    {
        $endpoint = $domain->routing_endpoint
            ?: (string) config('profile-domains.drivers.local.routing_endpoint', 'profiles.localhost');

        return new ProfileDomainProvisioningResult(
            status: ProfileDomainStatus::Active,
            tenantId: $domain->provider_tenant_id ?: "local-profile-{$domain->profile_id}",
            routingEndpoint: $endpoint,
            certificateStatus: 'local',
            dnsStatus: 'valid-configuration',
            dnsRecords: [$this->dnsRecord($domain, $endpoint)],
            providerStatus: 'local-active',
        );
    }

    public function disconnect(ProfileDomain $domain): void {}

    public function name(): string
    {
        return 'local';
    }

    /** @return array{name: string, type: string, value: string, purpose: string} */
    private function dnsRecord(ProfileDomain $domain, string $endpoint): array
    {
        return [
            'name' => $domain->hostname,
            'type' => 'CNAME_OR_ALIAS',
            'value' => $endpoint,
            'purpose' => 'traffic',
        ];
    }
}
