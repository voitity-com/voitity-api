<?php

namespace App\Http\Responses\Profile;

use App\Enums\ProfileDomainStatus;
use App\Models\ProfileDomain;

class ProfileDomainResponse
{
    public function __construct(private readonly ProfileDomain $domain) {}

    public function toArray(): array
    {
        return [
            'id' => $this->domain->id,
            'hostname' => $this->domain->hostname,
            'public_url' => $this->publicUrl(),
            'status' => $this->domain->status->value,
            'active' => $this->domain->status === ProfileDomainStatus::Active,
            'retryable' => $this->domain->status === ProfileDomainStatus::Failed
                && $this->domain->last_error_code !== 'disconnect',
            'dns_status' => $this->domain->dns_status,
            'certificate_status' => $this->domain->certificate_status,
            'dns_records' => array_values($this->domain->dns_records ?? []),
            'error' => $this->domain->last_error_message ? [
                'code' => $this->domain->last_error_code,
                'message' => $this->domain->last_error_message,
            ] : null,
            'requested_at' => $this->domain->requested_at?->toJSON(),
            'last_checked_at' => $this->domain->last_checked_at?->toJSON(),
            'activated_at' => $this->domain->activated_at?->toJSON(),
            'created_at' => $this->domain->created_at?->toJSON(),
            'updated_at' => $this->domain->updated_at?->toJSON(),
        ];
    }

    private function publicUrl(): string
    {
        if ($this->domain->provider === 'local') {
            $pattern = (string) config(
                'profile-domains.drivers.local.public_url_pattern',
                'http://{hostname}:3001'
            );

            return str_replace('{hostname}', $this->domain->hostname, $pattern);
        }

        return "https://{$this->domain->hostname}";
    }
}
