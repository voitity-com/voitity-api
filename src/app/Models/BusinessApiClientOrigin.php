<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessApiClientOrigin extends Model
{
    protected $fillable = ['business_api_client_id', 'origin'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(BusinessApiClient::class, 'business_api_client_id');
    }
}
