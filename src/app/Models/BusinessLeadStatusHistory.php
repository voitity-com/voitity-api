<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessLeadStatusHistory extends Model
{
    protected $fillable = ['business_lead_id', 'changed_by_user_id', 'from_status', 'to_status', 'note'];

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
