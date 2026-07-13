<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppNotification extends Model
{
    protected $fillable = [
        'user_id',
        'notification_key',
        'category',
        'kind',
        'visible_in_bell',
        'data',
        'action_url',
        'read_at',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'visible_in_bell' => 'boolean',
            'read_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
