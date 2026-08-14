<?php

declare(strict_types=1);

namespace Tests\Unit\ProfileDomains;

use App\Classes\ProfileDomainService\CloudFront\CloudFrontProfileDomainProvider;
use App\Enums\ProfileDomainStatus;
use App\Models\ProfileDomain;
use Aws\CloudFront\CloudFrontClient;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Tests\TestCase;

class CloudFrontProfileDomainProviderTest extends TestCase
{
    public function test_it_provisions_an_enabled_tenant_for_http_certificate_validation(): void
    {
        config([
            'profile-domains.drivers.cloudfront.distribution_id' => 'EDISTRIBUTION',
            'profile-domains.drivers.cloudfront.connection_group_id' => 'CGROUP',
            'profile-domains.drivers.cloudfront.routing_endpoint' => null,
        ]);
        $handler = new MockHandler;
        $handler->append(function (Command $command): Result {
            $this->assertSame([['Name' => 'profileId', 'Value' => '22']], $command['Parameters']);
            $this->assertTrue($command['Enabled']);

            return new Result([
                'DistributionTenant' => [
                    'Id' => 'DTENANT',
                    'Arn' => 'arn:aws:cloudfront::123456789012:distribution-tenant/DTENANT',
                    'Status' => 'InProgress',
                    'Enabled' => true,
                ],
            ]);
        });
        $handler->append(new Result([
            'ConnectionGroup' => ['Id' => 'CGROUP', 'RoutingEndpoint' => 'd111111abcdef8.cloudfront.net'],
        ]));
        $provider = new CloudFrontProfileDomainProvider($this->client($handler));

        $result = $provider->provision($this->domain());

        $this->assertSame(ProfileDomainStatus::PendingDns, $result->status);
        $this->assertSame('DTENANT', $result->tenantId);
        $this->assertSame('d111111abcdef8.cloudfront.net', $result->routingEndpoint);
        $this->assertSame('profile.example.org', $result->dnsRecords[0]['name']);
        $this->assertSame('CNAME_OR_ALIAS', $result->dnsRecords[0]['type']);
    }

    public function test_it_marks_a_deployed_enabled_tenant_active_after_dns_and_certificate_validation(): void
    {
        $handler = new MockHandler;
        $handler->append(new Result([
            'ETag' => 'etag-1',
            'DistributionTenant' => [
                'Id' => 'DTENANT',
                'Arn' => 'arn:aws:cloudfront::123456789012:distribution-tenant/DTENANT',
                'Status' => 'Deployed',
                'Enabled' => true,
                'Domains' => [['Domain' => 'profile.example.org', 'Status' => 'active']],
            ],
        ]));
        $handler->append(new Result([
            'ManagedCertificateDetails' => [
                'CertificateArn' => 'arn:aws:acm:us-east-1:123456789012:certificate/example',
                'CertificateStatus' => 'issued',
            ],
        ]));
        $handler->append(new Result([
            'DnsConfigurationList' => [[
                'Domain' => 'profile.example.org',
                'Status' => 'valid-configuration',
            ]],
        ]));
        $provider = new CloudFrontProfileDomainProvider($this->client($handler));

        $result = $provider->refresh($this->domain([
            'provider_tenant_id' => 'DTENANT',
            'routing_endpoint' => 'd111111abcdef8.cloudfront.net',
        ]));

        $this->assertSame(ProfileDomainStatus::Active, $result->status);
        $this->assertSame('issued', $result->certificateStatus);
        $this->assertSame('valid-configuration', $result->dnsStatus);
    }

    public function test_it_waits_until_cloudfront_activates_the_domain_association(): void
    {
        $handler = new MockHandler;
        $handler->append(new Result([
            'ETag' => 'etag-1',
            'DistributionTenant' => [
                'Id' => 'DTENANT',
                'Status' => 'Deployed',
                'Enabled' => true,
                'Domains' => [['Domain' => 'profile.example.org', 'Status' => 'inactive']],
            ],
        ]));
        $handler->append(new Result([
            'ManagedCertificateDetails' => ['CertificateStatus' => 'issued'],
        ]));
        $handler->append(new Result([
            'DnsConfigurationList' => [[
                'Domain' => 'profile.example.org',
                'Status' => 'valid-configuration',
            ]],
        ]));
        $provider = new CloudFrontProfileDomainProvider($this->client($handler));

        $result = $provider->refresh($this->domain([
            'provider_tenant_id' => 'DTENANT',
            'routing_endpoint' => 'd111111abcdef8.cloudfront.net',
        ]));

        $this->assertSame(ProfileDomainStatus::Activating, $result->status);
    }

    public function test_it_deletes_an_already_disabled_tenant(): void
    {
        $handler = new MockHandler;
        $handler->append(new Result([
            'ETag' => 'etag-delete',
            'DistributionTenant' => ['Id' => 'DTENANT', 'Status' => 'Deployed', 'Enabled' => false],
        ]));
        $handler->append(new Result([]));
        $provider = new CloudFrontProfileDomainProvider($this->client($handler));

        $provider->disconnect($this->domain(['provider_tenant_id' => 'DTENANT']));

        $this->assertTrue(true);
    }

    public function test_it_recovers_the_same_profiles_tenant_when_an_earlier_create_response_was_lost(): void
    {
        config([
            'profile-domains.drivers.cloudfront.distribution_id' => 'EDISTRIBUTION',
            'profile-domains.drivers.cloudfront.connection_group_id' => 'CGROUP',
            'profile-domains.drivers.cloudfront.routing_endpoint' => null,
        ]);
        $handler = new MockHandler;
        $handler->append(new AwsException(
            'The domain already exists.',
            new Command('CreateDistributionTenant'),
            ['code' => 'CNAMEAlreadyExists']
        ));
        $handler->append(new Result([
            'DistributionTenant' => [
                'Id' => 'DTENANT-RECOVERED',
                'Arn' => 'arn:aws:cloudfront::123456789012:distribution-tenant/DTENANT-RECOVERED',
                'DistributionId' => 'EDISTRIBUTION',
                'ConnectionGroupId' => 'CGROUP',
                'Parameters' => [['Name' => 'profileId', 'Value' => '22']],
                'Status' => 'InProgress',
                'Enabled' => true,
            ],
        ]));
        $handler->append(new Result([
            'ConnectionGroup' => ['Id' => 'CGROUP', 'RoutingEndpoint' => 'd111111abcdef8.cloudfront.net'],
        ]));
        $provider = new CloudFrontProfileDomainProvider($this->client($handler));

        $result = $provider->provision($this->domain());

        $this->assertSame(ProfileDomainStatus::PendingDns, $result->status);
        $this->assertSame('DTENANT-RECOVERED', $result->tenantId);
        $this->assertSame(0, count($handler));
    }

    private function client(MockHandler $handler): CloudFrontClient
    {
        return new CloudFrontClient([
            'credentials' => false,
            'handler' => $handler,
            'region' => 'us-east-1',
            'version' => 'latest',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function domain(array $attributes = []): ProfileDomain
    {
        $domain = new ProfileDomain([
            'profile_id' => 22,
            'hostname' => 'profile.example.org',
            'provider' => 'cloudfront',
            'status' => ProfileDomainStatus::PendingDns->value,
            ...$attributes,
        ]);
        $domain->id = 10;

        return $domain;
    }
}
