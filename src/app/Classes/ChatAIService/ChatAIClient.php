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
     * @param int|null $chatId The chat ID used to load recent conversation context
     * @param int|null $currentMessageId The current message ID to exclude from recent context
     * @return ChatAIAnswer The AI answer response
     */
    public function getAnswer(Profile $profile, string $message, ?int $chatId = null, ?int $currentMessageId = null): ChatAIAnswer;

    /**
     * Convert audio to text based on a profile and audio file path.
     *
     * @param string $audioPath The path to the audio file
     * @return ChatAITextFromAudio The text extracted from audio
     */
    public function getTextFromAudio(string $audioPath): ChatAITextFromAudio;
}
