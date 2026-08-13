<?php

namespace App\Classes\ProfileDomainService\CloudFront;

use App\Classes\ProfileDomainService\ProfileDomainProvider;
use App\Classes\ProfileDomainService\ProfileDomainProvisioningResult;
use App\Enums\ProfileDomainStatus;
use App\Models\ProfileDomain;
use Aws\CloudFront\CloudFrontClient;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CloudFrontProfileDomainProvider implements ProfileDomainProvider
{
    public function __construct(private readonly CloudFrontClient $client) {}

    public function provision(ProfileDomain $domain): ProfileDomainProvisioningResult
    {
        $distributionId = $this->requiredConfig('distribution_id');
        $connectionGroupId = $this->requiredConfig('connection_group_id');

        try {
            $result = $this->client->createDistributionTenant([
                'DistributionId' => $distributionId,
                'ConnectionGroupId' => $connectionGroupId,
                'Name' => sprintf('bigmelo-profile-%d-%s', $domain->profile_id, substr(hash('sha256', $domain->hostname), 0, 10)),
                'Domains' => [['Domain' => $domain->hostname]],
                'Parameters' => [[
                    'Name' => 'profileId',
                    'Value' => (string) $domain->profile_id,
                ]],
                'Enabled' => false,
                'ManagedCertificateRequest' => [
                    'ValidationTokenHost' => (string) config(
                        'profile-domains.drivers.cloudfront.validation_token_host',
                        'cloudfront'
                    ),
                    'PrimaryDomainName' => $domain->hostname,
                    'CertificateTransparencyLoggingPreference' => 'enabled',
                ],
                'Tags' => ['Items' => [
                    ['Key' => 'Application', 'Value' => 'bigmelo'],
                    ['Key' => 'ProfileId', 'Value' => (string) $domain->profile_id],
                    ['Key' => 'ManagedBy', 'Value' => 'voitity-api'],
                ]],
            ]);
            $tenant = (array) $result->get('DistributionTenant');
            $endpoint = $this->routingEndpoint($connectionGroupId);

            return $this->provisioningResult($domain, $tenant, $endpoint);
        } catch (AwsException $exception) {
            if (in_array($exception->getAwsErrorCode(), ['CNAMEAlreadyExists', 'EntityAlreadyExists'], true)) {
                $tenant = $this->recoverTenantCreatedByAnEarlierAttempt($domain, $distributionId, $connectionGroupId);

                if ($tenant !== null) {
                    $endpoint = $this->routingEndpoint($connectionGroupId);

                    Log::warning('Profile domain recovered an existing CloudFront tenant after a duplicate response.', [
                        'profile_id' => $domain->profile_id,
                        'profile_domain_id' => $domain->id,
                        'provider_tenant_id' => $tenant['Id'] ?? null,
                    ]);

                    return $this->provisioningResult($domain, $tenant, $endpoint);
                }
            }

            $this->logAwsFailure('createDistributionTenant', $domain, $exception);
            throw new RuntimeException('CloudFront could not create the domain tenant.', previous: $exception);
        }
    }

    public function refresh(ProfileDomain $domain): ProfileDomainProvisioningResult
    {
        $tenantId = $domain->provider_tenant_id;

        if (! filled($tenantId)) {
            throw new RuntimeException('The CloudFront distribution tenant identifier is missing.');
        }

        try {
            $tenantResult = $this->client->getDistributionTenant(['Identifier' => $tenantId]);
            $tenant = (array) $tenantResult->get('DistributionTenant');
            $certificate = (array) $this->client
                ->getManagedCertificateDetails(['Identifier' => $tenantId])
                ->get('ManagedCertificateDetails');
            $dnsConfigurations = (array) $this->client
                ->verifyDnsConfiguration(['Identifier' => $tenantId, 'Domain' => $domain->hostname])
                ->get('DnsConfigurationList');
            $dns = (array) ($dnsConfigurations[0] ?? []);
            $certificateStatus = $this->normalizedString($certificate['CertificateStatus'] ?? null);
            $dnsStatus = $this->normalizedString($dns['Status'] ?? null);
            $enabled = (bool) ($tenant['Enabled'] ?? false);
            $providerStatus = $this->normalizedString($tenant['Status'] ?? null);

            if ($certificateStatus === 'issued' && $dnsStatus === 'valid-configuration' && ! $enabled) {
                $this->client->updateDistributionTenant([
                    'Id' => $tenantId,
                    'IfMatch' => (string) $tenantResult->get('ETag'),
                    'Enabled' => true,
                ]);
                $enabled = true;
                $providerStatus = 'activating';
            }

            $domainStatus = $this->status($certificateStatus, $dnsStatus, $enabled, $providerStatus);
            $endpoint = $domain->routing_endpoint
                ?: $this->routingEndpoint($this->requiredConfig('connection_group_id'));

            return new ProfileDomainProvisioningResult(
                status: $domainStatus,
                tenantId: $tenantId,
                tenantArn: $this->string($tenant['Arn'] ?? $domain->provider_tenant_arn),
                routingEndpoint: $endpoint,
                certificateArn: $this->string($certificate['CertificateArn'] ?? null),
                certificateStatus: $certificateStatus,
                dnsStatus: $dnsStatus,
                dnsRecords: [$this->dnsRecord($domain, $endpoint)],
                providerStatus: $providerStatus,
            );
        } catch (AwsException $exception) {
            $this->logAwsFailure('refreshDistributionTenant', $domain, $exception);
            throw new RuntimeException('CloudFront could not verify the domain configuration.', previous: $exception);
        }
    }

    public function disconnect(ProfileDomain $domain): void
    {
        if (! filled($domain->provider_tenant_id)) {
            return;
        }

        try {
            $result = $this->client->getDistributionTenant(['Identifier' => $domain->provider_tenant_id]);
            $tenant = (array) $result->get('DistributionTenant');

            if ((bool) ($tenant['Enabled'] ?? false)) {
                $this->client->updateDistributionTenant([
                    'Id' => $domain->provider_tenant_id,
                    'IfMatch' => (string) $result->get('ETag'),
                    'Enabled' => false,
                ]);

                throw new RuntimeException('CloudFront is disabling the domain tenant. The cleanup will retry.');
            }

            $this->client->deleteDistributionTenant([
                'Id' => $domain->provider_tenant_id,
                'IfMatch' => (string) $result->get('ETag'),
            ]);
        } catch (AwsException $exception) {
            $this->logAwsFailure('deleteDistributionTenant', $domain, $exception);
            throw new RuntimeException('CloudFront could not remove the domain tenant yet.', previous: $exception);
        }
    }

    public function name(): string
    {
        return 'cloudfront';
    }

    private function status(?string $certificate, ?string $dns, bool $enabled, ?string $provider): ProfileDomainStatus
    {
        if ($certificate === 'failed' || $certificate === 'validation-timed-out' || $certificate === 'revoked') {
            return ProfileDomainStatus::Failed;
        }

        if ($dns !== 'valid-configuration') {
            return ProfileDomainStatus::PendingDns;
        }

        if ($certificate !== 'issued') {
            return ProfileDomainStatus::PendingCertificate;
        }

        if (! $enabled || ! in_array($provider, ['deployed', 'active'], true)) {
            return ProfileDomainStatus::Activating;
        }

        return ProfileDomainStatus::Active;
    }

    private function routingEndpoint(string $connectionGroupId): string
    {
        $configured = trim((string) config('profile-domains.drivers.cloudfront.routing_endpoint'));

        if ($configured !== '') {
            return $configured;
        }

        $group = (array) $this->client
            ->getConnectionGroup(['Identifier' => $connectionGroupId])
            ->get('ConnectionGroup');

        return $this->string($group['RoutingEndpoint'] ?? null)
            ?: throw new RuntimeException('The CloudFront connection group has no routing endpoint.');
    }

    /** @param array<string, mixed> $tenant */
    private function provisioningResult(
        ProfileDomain $domain,
        array $tenant,
        string $endpoint,
    ): ProfileDomainProvisioningResult {
        return new ProfileDomainProvisioningResult(
            status: ProfileDomainStatus::PendingDns,
            tenantId: $this->string($tenant['Id'] ?? null),
            tenantArn: $this->string($tenant['Arn'] ?? null),
            routingEndpoint: $endpoint,
            certificateStatus: 'pending-validation',
            dnsStatus: 'unknown-configuration',
            dnsRecords: [$this->dnsRecord($domain, $endpoint)],
            providerStatus: $this->normalizedString($tenant['Status'] ?? null),
        );
    }

    /** @return array<string, mixed>|null */
    private function recoverTenantCreatedByAnEarlierAttempt(
        ProfileDomain $domain,
        string $distributionId,
        string $connectionGroupId,
    ): ?array {
        try {
            $tenant = (array) $this->client
                ->getDistributionTenantByDomain(['Domain' => $domain->hostname])
                ->get('DistributionTenant');
        } catch (AwsException $lookupException) {
            $this->logAwsFailure('getDistributionTenantByDomain', $domain, $lookupException);

            return null;
        }

        $profileId = (string) $domain->profile_id;
        $parameters = (array) ($tenant['Parameters'] ?? []);
        $tags = (array) (($tenant['Tags'] ?? [])['Items'] ?? []);
        $belongsToProfile = collect([...$parameters, ...$tags])->contains(
            fn (mixed $item): bool => is_array($item)
                && in_array($item['Name'] ?? $item['Key'] ?? null, ['profileId', 'ProfileId'], true)
                && (string) ($item['Value'] ?? '') === $profileId
        );

        if (($tenant['DistributionId'] ?? null) !== $distributionId
            || ($tenant['ConnectionGroupId'] ?? null) !== $connectionGroupId
            || ! $belongsToProfile) {
            Log::warning('Profile domain duplicate belongs to a different CloudFront tenant.', [
                'profile_id' => $domain->profile_id,
                'profile_domain_id' => $domain->id,
                'provider_tenant_id' => $tenant['Id'] ?? null,
            ]);

            return null;
        }

        return $tenant;
    }

    /** @return array{name: string, type: string, value: string, purpose: string} */
    private function dnsRecord(ProfileDomain $domain, string $endpoint): array
    {
        return [
            'name' => $domain->hostname,
            'type' => 'CNAME_OR_ALIAS',
            'value' => $endpoint,
            'purpose' => 'traffic_and_certificate_validation',
        ];
    }

    private function requiredConfig(string $key): string
    {
        $value = trim((string) config("profile-domains.drivers.cloudfront.{$key}"));

        return $value !== '' ? $value : throw new RuntimeException("Missing CloudFront domain setting: {$key}.");
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function normalizedString(mixed $value): ?string
    {
        $value = $this->string($value);

        return $value === null ? null : strtolower($value);
    }

    private function logAwsFailure(string $operation, ProfileDomain $domain, AwsException $exception): void
    {
        Log::error('Profile domain CloudFront request failed.', [
            'operation' => $operation,
            'profile_id' => $domain->profile_id,
            'profile_domain_id' => $domain->id,
            'provider_tenant_id' => $domain->provider_tenant_id,
            'aws_error_code' => $exception->getAwsErrorCode(),
            'aws_error_type' => $exception->getAwsErrorType(),
            'status_code' => $exception->getStatusCode(),
            'request_id' => $exception->getAwsRequestId(),
        ]);
    }
}
