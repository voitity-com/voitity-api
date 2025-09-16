<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VoiceSample extends Model
{
    use SoftDeletes;
    
    protected $fillable = [
        'voice_id',
        'file',
        'duration',
        'active',
    ];

    protected $casts = [
        'duration' => 'integer',
        'active' => 'boolean',
    ];

    public function voice()
    {
        return $this->belongsTo(Voice::class);
    }
}
