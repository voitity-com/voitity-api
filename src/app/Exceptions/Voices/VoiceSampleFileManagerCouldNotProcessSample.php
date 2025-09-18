<?php

namespace App\Exceptions\Voices;

use Exception;

class VoiceSampleFileManagerCouldNotProcessSample extends Exception
{
    protected $message = 'Voice sample file could not be processed.';
    
    public function __construct(string $message = null, int $code = 0, Exception $previous = null)
    {
        if ($message) {
            $this->message = $message;
        }
        
        parent::__construct($this->message, $code, $previous);
    }
}
