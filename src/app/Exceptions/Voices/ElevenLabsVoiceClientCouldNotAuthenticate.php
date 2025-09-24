<?php

namespace App\Exceptions\Voices;

use Exception;

class ElevenLabsVoiceClientCouldNotAuthenticate extends Exception
{
    protected $message = 'ElevenLabs Voice Client could not authenticate with the API.';
    
    public function __construct(string $message = null, int $code = 0, Exception $previous = null)
    {
        if ($message) {
            $this->message = $message;
        }
        
        parent::__construct($this->message, $code, $previous);
    }
}
