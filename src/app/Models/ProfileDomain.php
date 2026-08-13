<?php

namespace App\Models;

use App\Enums\ProfileDomainStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileDomain extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'hostname',
        'status',
        'provider',
        'provider_tenant_id',
        'provider_tenant_arn',
        'routing_endpoint',
        'certificate_arn',
        'certificate_status',
        'dns_status',
        'dns_records',
        'provider_status',
        'last_error_code',
        'last_error_message',
        'requested_at',
        'provisioned_at',
        'last_checked_at',
        'verified_at',
        'activated_at',
        'disconnected_at',
    ];

    protected $casts = [
        'status' => ProfileDomainStatus::class,
        'dns_records' => 'array',
        'requested_at' => 'datetime',
        'provisioned_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'verified_at' => 'datetime',
        'activated_at' => 'datetime',
        'disconnected_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
