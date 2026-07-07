<?php

namespace App\Classes\ProfileKnowledgeAIService;

use App\Models\Profile;
use Illuminate\Support\Facades\Log;

class ProfileKnowledgeAIService
{
    public function __construct(private readonly ProfileKnowledgeAIManager $manager) {}

    public function structureCv(Profile $profile, string $text): ProfileKnowledgeStructure
    {
        if ((bool) config('profile-knowledge-ai.enabled', false)) {
            $result = $this->manager->driver()->structureCv($profile, $text);

            if ($result->isSuccessful() && $result->hasData()) {
                return $result;
            }

            Log::warning('Profile knowledge AI structuring failed, using fallback driver.', [
                'profile_id' => $profile->id,
                'source' => $result->source,
                'status' => $result->status,
                'request_url' => $result->requestUrl,
            ]);
        }

        $fallbackDriver = (string) config('profile-knowledge-ai.fallback_driver', 'local');

        return $this->manager->driver($fallbackDriver)->structureCv($profile, $text);
    }

    public function getManager(): ProfileKnowledgeAIManager
    {
        return $this->manager;
    }
}
