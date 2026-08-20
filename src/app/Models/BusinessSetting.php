<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessSetting extends Model
{
    protected $fillable = [
        'business_id', 'lead_recipient_email', 'sender_email', 'sender_name', 'reply_to_email',
        'locale', 'widget_enabled', 'widget_title', 'widget_button_label', 'widget_welcome_message',
        'widget_primary_color', 'widget_position',
    ];

    protected $casts = ['widget_enabled' => 'boolean'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
