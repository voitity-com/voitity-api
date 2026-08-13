<?php

namespace App\Services;

use App\Classes\ProfileDomainService\ProfileDomainProvisioningResult;
use App\Enums\ProfileDomainStatus;
use App\Models\ProfileDomain;

class ProfileDomainService
{
    public function apply(ProfileDomain $domain, ProfileDomainProvisioningResult $result): ProfileDomain
    {
        $becameActive = $result->status === ProfileDomainStatus::Active
            && $domain->status !== ProfileDomainStatus::Active;
        $failed = $result->status === ProfileDomainStatus::Failed;

        $domain->forceFill([
            'status' => $result->status->value,
            'provider_tenant_id' => $result->tenantId ?: $domain->provider_tenant_id,
            'provider_tenant_arn' => $result->tenantArn ?: $domain->provider_tenant_arn,
            'routing_endpoint' => $result->routingEndpoint ?: $domain->routing_endpoint,
            'certificate_arn' => $result->certificateArn ?: $domain->certificate_arn,
            'certificate_status' => $result->certificateStatus,
            'dns_status' => $result->dnsStatus,
            'dns_records' => $result->dnsRecords ?: $domain->dns_records,
            'provider_status' => $result->providerStatus,
            'last_error_code' => $failed ? 'certificate' : null,
            'last_error_message' => $failed
                ? 'CloudFront could not issue the certificate. Check the DNS record and retry verification.'
                : null,
            'provisioned_at' => $domain->provisioned_at ?? now(),
            'last_checked_at' => now(),
            'verified_at' => $result->dnsStatus === 'valid-configuration'
                ? ($domain->verified_at ?? now())
                : $domain->verified_at,
            'activated_at' => $becameActive ? now() : $domain->activated_at,
        ])->save();

        return $domain->refresh();
    }

    public function markFailed(ProfileDomain $domain, string $operation, ?\Throwable $exception = null): void
    {
        $domain->forceFill([
            'status' => ProfileDomainStatus::Failed->value,
            'last_error_code' => $operation,
            'last_error_message' => match ($operation) {
                'disconnect' => 'The domain could not be disconnected yet. Please try again.',
                'verify' => 'The domain configuration could not be verified. Please try again.',
                default => 'The domain could not be configured. Please try again.',
            },
            'last_checked_at' => now(),
        ])->save();
    }
}
