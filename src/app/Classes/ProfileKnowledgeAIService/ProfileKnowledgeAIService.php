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

            if ($result->isSuccessful() && $result->hasData() && ! $this->needsFallbackNormalization($result)) {
                return $result;
            }

            Log::warning('Profile knowledge AI structuring failed, using fallback driver.', [
                'profile_id' => $profile->id,
                'source' => $result->source,
                'status' => $result->status,
                'request_url' => $result->requestUrl,
                'reason' => $result->isSuccessful() && $result->hasData() ? 'cross_section_content' : 'failed_or_empty',
            ]);
        }

        $fallbackDriver = (string) config('profile-knowledge-ai.fallback_driver', 'local');

        return $this->manager->driver($fallbackDriver)->structureCv($profile, $text);
    }

    public function getManager(): ProfileKnowledgeAIManager
    {
        return $this->manager;
    }

    private function needsFallbackNormalization(ProfileKnowledgeStructure $result): bool
    {
        $sectionHeadings = [
            'education',
            'educacion',
            'experiencia',
            'experience',
            'habilidades',
            'projects',
            'proyectos',
            'skills',
            'tecnologias',
            'technologies',
        ];

        foreach ($result->items('projects') as $project) {
            $name = $this->normalizeLabel((string) ($project['name'] ?? ''));

            if (in_array($name, $sectionHeadings, true) || $this->looksLikeSkillList((string) ($project['name'] ?? ''))) {
                return true;
            }
        }

        foreach (['work', 'projects'] as $section) {
            foreach ($result->items($section) as $item) {
                $content = implode("\n", array_filter([
                    $item['description'] ?? '',
                    implode("\n", is_array($item['highlights'] ?? null) ? $item['highlights'] : []),
                ]));

                if ($this->containsCrossSectionMarkers($content)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function containsCrossSectionMarkers(string $content): bool
    {
        if ($content === '') {
            return false;
        }

        $matches = preg_match_all(
            '/(?:^|\R)\s*(education|educacion|experiencia|experience|habilidades|projects|proyectos|skills|tecnologias|technologies)\s*:?\s*(?:\R|$)/iu',
            $content
        );

        return $matches !== false && $matches >= 1;
    }

    private function normalizeLabel(string $value): string
    {
        $value = trim($value);
        $value = str($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9 ]/', ' ')
            ->squish()
            ->toString();

        return $value;
    }

    private function looksLikeSkillList(string $value): bool
    {
        $value = trim($value);

        if (substr_count($value, ',') < 1) {
            return false;
        }

        return ! preg_match('/\b(app|application|chatbot|integration|platform|project|system|tool|website)\b/i', $value);
    }
}
