<?php

namespace App\Models;

use App\Enums\ChatEndReason;
use App\Enums\ChatStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Chat extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'profile_id',
        'status',
        'started_at',
        'last_activity_at',
        'ended_at',
        'ended_reason',
        'visitor_id_hash',
    ];

    protected $casts = [
        'status' => ChatStatus::class,
        'started_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'ended_at' => 'datetime',
        'ended_reason' => ChatEndReason::class,
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function analysis(): HasOne
    {
        return $this->hasOne(ChatAnalysis::class);
    }
}
