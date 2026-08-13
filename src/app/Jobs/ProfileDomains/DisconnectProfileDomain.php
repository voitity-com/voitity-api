<?php

namespace App\Jobs\ProfileDomains;

use App\Classes\ProfileDomainService\ProfileDomainManager;
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

class DisconnectProfileDomain implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 12;

    public int $timeout = 120;

    public array $backoff = [30, 60, 120, 180, 300];

    public function __construct(public readonly int $profileDomainId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("profile-domain:{$this->profileDomainId}"))->releaseAfter(15)->expireAfter(300)];
    }

    public function handle(ProfileDomainManager $domains): void
    {
        $domain = ProfileDomain::query()->find($this->profileDomainId);

        if (! $domain) {
            return;
        }

        $provider = $domains->driver($domain->provider);
        Log::info('Profile domain disconnection started.', $this->context($domain));
        $provider->disconnect($domain);
        $context = $this->context($domain);
        $domain->delete();
        Log::info('Profile domain disconnection completed.', $context);
    }

    public function failed(?Throwable $exception): void
    {
        $domain = ProfileDomain::query()->find($this->profileDomainId);

        if (! $domain) {
            return;
        }

        app(ProfileDomainService::class)->markFailed($domain, 'disconnect', $exception);
        Log::error('Profile domain disconnection failed permanently.', [
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
            'provider_tenant_id' => $domain->provider_tenant_id,
        ];
    }
}
