<?php

namespace App\Exceptions\Voices;

use Exception;

class ElevenLabsVoiceClientCouldNotAddSample extends Exception
{
    protected $message = 'ElevenLabs Voice Client could not add the voice sample.';
    
    public function __construct(string $message = null, int $code = 0, Exception $previous = null)
    {
        if ($message) {
            $this->message = $message;
        }
        
        parent::__construct($this->message, $code, $previous);
    }
}
