<?php

namespace App\Classes\ChatAIService;

use App\Models\Profile;

interface ChatAIClient
{
    /**
     * Get an AI answer based on a profile and message.
     *
     * @param Profile $profile The user profile for context
     * @param string $message The message to get an answer for
     * @return ChatAIAnswer The AI answer response
     */
    public function getAnswer(Profile $profile, string $message): ChatAIAnswer;

    /**
     * Convert audio to text based on a profile and audio file path.
     *
     * @param string $audioPath The path to the audio file
     * @return ChatAITextFromAudio The text extracted from audio
     */
    public function getTextFromAudio(string $audioPath): ChatAITextFromAudio;
}
