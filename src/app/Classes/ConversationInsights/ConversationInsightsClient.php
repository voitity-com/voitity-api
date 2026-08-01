<?php

namespace App\Classes\ConversationInsights;

use App\Models\Chat;

interface ConversationInsightsClient
{
    public function classify(Chat $chat): ConversationClassification;
}
