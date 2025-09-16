<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoiceProviderRequest extends Model
{
    protected $fillable = [
        'voice_id',
        'voice_sample_id',
        'source',
        'request_url',
        'response',
        'status',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function voice()
    {
        return $this->belongsTo(Voice::class);
    }

    public function voiceSample()
    {
        return $this->belongsTo(VoiceSample::class);
    }
}
