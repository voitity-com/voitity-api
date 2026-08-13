<?php

namespace App\Jobs\ProfileDomains;

use App\Classes\ProfileDomainService\ProfileDomainManager;
use App\Enums\ProfileDomainStatus;
use App\Models\ProfileDomain;
use App\Services\ProfileDomainService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshProfileDomain implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $profileDomainId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("profile-domain:{$this->profileDomainId}"))->releaseAfter(15)->expireAfter(300)];
    }

    public function handle(ProfileDomainManager $domains, ProfileDomainService $service): void
    {
        $domain = ProfileDomain::query()->find($this->profileDomainId);

        if (! $domain || $domain->status === ProfileDomainStatus::Disconnecting) {
            return;
        }

        $provider = $domains->driver($domain->provider);
        Log::info('Profile domain verification started.', $this->context($domain));
        $result = filled($domain->provider_tenant_id)
            ? $provider->refresh($domain)
            : $provider->provision($domain);
        $service->apply($domain, $result);
        Log::info('Profile domain verification completed.', $this->context($domain->fresh()));
    }

    public function failed(?Throwable $exception): void
    {
        $domain = ProfileDomain::query()->find($this->profileDomainId);

        if (! $domain) {
            return;
        }

        if ($domain->status === ProfileDomainStatus::Active) {
            $domain->forceFill([
                'last_error_code' => 'background_verify',
                'last_error_message' => 'The latest domain health check failed. Traffic remains active while Bigmelo retries.',
                'last_checked_at' => now(),
            ])->save();
        } else {
            app(ProfileDomainService::class)->markFailed($domain, 'verify', $exception);
        }
        Log::error('Profile domain verification failed permanently.', [
            ...$this->context($domain),
            'exception_class' => $this->exceptionClass($exception),
        ]);
    }

    private function exceptionClass(?Throwable $exception): ?string
    {
        if (! $exception) {
            return null;
        }

        $root = $exception->getPrevious() ?: $exception;

        return $root::class;
    }

    private function context(ProfileDomain $domain): array
    {
        return [
            'attempt' => max(1, $this->attempts()),
            'profile_id' => $domain->profile_id,
            'profile_domain_id' => $domain->id,
            'hostname' => $domain->hostname,
            'provider' => $domain->provider,
            'status' => $domain->status->value,
            'dns_status' => $domain->dns_status,
            'certificate_status' => $domain->certificate_status,
        ];
    }
}
