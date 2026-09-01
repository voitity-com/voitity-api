<?php

namespace App\Models;

use App\Enums\ActivationEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivationEvent extends Model
{
    protected $fillable = [
        'user_id',
        'profile_id',
        'subscription_id',
        'event_type',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'metadata',
        'occurred_at',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => ActivationEventType::class,
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
