<?php

namespace App\Classes\VoiceService;

use App\Models\Voice;

interface DeletesProviderVoices
{
    public function deleteProviderVoice(Voice $voice, string $providerVoiceId): bool;
}
