<?php

namespace App\Classes\ProfileKnowledgeAIService;

use App\Models\Profile;

interface ProfileKnowledgeAIClient
{
    public function structureCv(Profile $profile, string $text): ProfileKnowledgeStructure;
}
