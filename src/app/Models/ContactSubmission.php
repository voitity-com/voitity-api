<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactSubmission extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone_country_code',
        'phone_number',
        'message',
        'locale',
        'source',
        'consent_accepted_at',
        'ip_address',
        'user_agent',
        'metadata',
        'notified_at',
        'notification_error',
    ];

    protected function casts(): array
    {
        return [
            'consent_accepted_at' => 'datetime',
            'metadata' => 'array',
            'notified_at' => 'datetime',
        ];
    }

    public function phone(): string
    {
        return trim($this->phone_country_code.' '.$this->phone_number);
    }
}
