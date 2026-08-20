<?php

namespace App\Models;

use App\Enums\BusinessStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Business extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['created_by_user_id', 'name', 'description', 'status', 'activated_at'];

    protected $casts = ['status' => BusinessStatus::class, 'activated_at' => 'datetime'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function settings(): HasOne
    {
        return $this->hasOne(BusinessSetting::class);
    }

    public function flow(): HasOne
    {
        return $this->hasOne(BusinessFlow::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(BusinessSource::class);
    }

    public function apiClients(): HasMany
    {
        return $this->hasMany(BusinessApiClient::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(BusinessConversation::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(BusinessLead::class);
    }

    public function usageEvents(): HasMany
    {
        return $this->hasMany(BusinessUsageEvent::class);
    }

    public function messages(): HasManyThrough
    {
        return $this->hasManyThrough(
            BusinessMessage::class,
            BusinessConversation::class,
            'business_id',
            'business_conversation_id',
        );
    }
}
