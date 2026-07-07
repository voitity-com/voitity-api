<?php

namespace App\Classes\ProfileKnowledge;

use App\Classes\ProfileKnowledgeAIService\Local\LocalProfileKnowledgeClient;
use App\Models\Profile;
use App\Models\ProfileSource;
use App\Models\ProfileSourceItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProfileDataSynchronizer
{
    public function syncApprovedSource(Profile $profile, ProfileSource $source): Profile
    {
        $source->loadMissing(['items']);

        $data = is_array($profile->data) ? $profile->data : [];
        $data = $this->removeCvGeneratedItems($data, $source->items);

        foreach ($source->items as $item) {
            $data = $this->mergeItem($data, $item);
        }

        $profile->data = $data;
        $profile->save();

        return $profile->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergeItem(array $data, ProfileSourceItem $item): array
    {
        return match ($item->type) {
            'summary' => $this->mergeSummary($data, $item),
            'experience' => $this->mergeExperience($data, $item),
            'projects' => $this->mergeProjects($data, $item),
            'skills' => $this->mergeSkills($data, $item),
            'education' => $this->mergeEducation($data, $item),
            'achievements' => $this->mergeAchievements($data, $item),
            default => $this->mergeGenericSection($data, $item),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergeSummary(array $data, ProfileSourceItem $item): array
    {
        $me = $this->objectSection($data['me'] ?? []);
        $structured = $this->structuredData($item);
        $me['description'] = $this->cleanText((string) ($structured['summary'] ?? $item->content));
        $data['me'] = $me;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergeExperience(array $data, ProfileSourceItem $item): array
    {
        $structured = $this->structuredData($item);
        $work = $this->hasAnyValue($structured, ['company', 'role', 'description', 'date_range', 'duration'])
            ? [$this->workFromStructuredData($structured, $item)]
            : $this->legacyWorkItems($item);

        if ($work === []) {
            $work = [[
                'company' => $this->firstLine($item->content) ?: $item->title ?: 'CV',
                'role' => $item->title ?: 'Experience',
                'description' => $this->cleanText($item->content),
                'source' => 'cv',
                'source_id' => $item->profile_source_id,
                'source_item_id' => $item->id,
            ]];
        }

        $data['work'] = $this->mergeSectionItems($data['work'] ?? [], $work);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergeProjects(array $data, ProfileSourceItem $item): array
    {
        $structured = $this->structuredData($item);
        $projects = $this->hasAnyValue($structured, ['name', 'description', 'url'])
            ? [$this->projectFromStructuredData($structured, $item)]
            : collect($this->contentLines($item->content))
                ->filter(fn (string $line): bool => mb_strlen($line) >= 15)
                ->map(fn (string $line): array => [
                    'name' => $this->projectName($line),
                    'description' => $line,
                    'url' => '',
                    'source' => 'cv',
                    'source_id' => $item->profile_source_id,
                    'source_item_id' => $item->id,
                ])
                ->values()
                ->all();

        if ($projects === []) {
            $projects = [[
                'name' => $item->title ?: 'CV Project',
                'description' => $this->cleanText($item->content),
                'url' => '',
                'source' => 'cv',
                'source_id' => $item->profile_source_id,
                'source_item_id' => $item->id,
            ]];
        }

        $data['projects'] = $this->mergeSectionItems($data['projects'] ?? [], $projects);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergeSkills(array $data, ProfileSourceItem $item): array
    {
        $structured = $this->structuredData($item);
        $skills = $this->hasAnyValue($structured, ['name', 'description', 'category'])
            ? [$this->skillFromStructuredData($structured, $item)]
            : collect(preg_split('/[,\n]+/u', $item->content) ?: [])
                ->map(fn (string $skill): string => trim($skill, " \t\n\r\0\x0B.-"))
                ->filter(fn (string $skill): bool => $skill !== '')
                ->map(fn (string $skill): array => [
                    'name' => $skill,
                    'description' => '',
                    'source' => 'cv',
                    'source_id' => $item->profile_source_id,
                    'source_item_id' => $item->id,
                ])
                ->values()
                ->all();

        $data['skills'] = $this->mergeSectionItems($data['skills'] ?? [], $skills);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergeEducation(array $data, ProfileSourceItem $item): array
    {
        $structured = $this->structuredData($item);

        if ($this->hasAnyValue($structured, ['institution', 'degree', 'description'])) {
            $data['education'] = $this->mergeSectionItems($data['education'] ?? [], [
                $this->educationFromStructuredData($structured, $item),
            ]);

            return $data;
        }

        $lines = $this->contentLines($item->content);

        $data['education'] = $this->mergeSectionItems($data['education'] ?? [], [[
            'institution' => $lines[0] ?? '',
            'degree' => $lines[1] ?? '',
            'description' => $this->cleanText($item->content),
            'source' => 'cv',
            'source_id' => $item->profile_source_id,
            'source_item_id' => $item->id,
        ]]);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergeAchievements(array $data, ProfileSourceItem $item): array
    {
        $structured = $this->structuredData($item);
        $achievements = $this->hasAnyValue($structured, ['name', 'description', 'date'])
            ? [$this->achievementFromStructuredData($structured, $item)]
            : collect($this->contentLines($item->content))
                ->filter(fn (string $line): bool => mb_strlen($line) >= 10)
                ->map(fn (string $line): array => [
                    'name' => $this->projectName($line),
                    'description' => $line,
                    'source' => 'cv',
                    'source_id' => $item->profile_source_id,
                    'source_item_id' => $item->id,
                ])
                ->values()
                ->all();

        $data['achievements'] = $this->mergeSectionItems($data['achievements'] ?? [], $achievements);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergeGenericSection(array $data, ProfileSourceItem $item): array
    {
        $data[$item->type] = $this->mergeSectionItems($data[$item->type] ?? [], [[
            'name' => $item->title ?: Str::headline($item->type),
            'description' => $this->cleanText($item->content),
            'source' => 'cv',
            'source_id' => $item->profile_source_id,
            'source_item_id' => $item->id,
        ]]);

        return $data;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function mergeSectionItems(mixed $current, array $items): array
    {
        return [...$this->listSection($current), ...$items];
    }

    /**
     * @param  Collection<int, ProfileSourceItem>  $items
     * @return array<string, mixed>
     */
    private function removeCvGeneratedItems(array $data, Collection $items): array
    {
        $sourceIds = $items
            ->map(fn (ProfileSourceItem $item): int => (int) $item->profile_source_id)
            ->unique()
            ->values();

        $items
            ->map(fn (ProfileSourceItem $item): string => $this->dataSectionForItem($item))
            ->filter(fn (string $section): bool => $section !== 'me')
            ->unique()
            ->each(function (string $section) use (&$data, $sourceIds): void {
                $data[$section] = collect($this->listSection($data[$section] ?? []))
                    ->filter(fn (mixed $item): bool => ! $this->shouldRemoveGeneratedItem($item, $sourceIds))
                    ->values()
                    ->all();
            });

        return $data;
    }

    /**
     * @param  Collection<int, int>  $sourceIds
     */
    private function shouldRemoveGeneratedItem(mixed $item, Collection $sourceIds): bool
    {
        if (! is_array($item) || ($item['source'] ?? null) !== 'cv') {
            return false;
        }

        if (! isset($item['source_id'])) {
            return true;
        }

        return $sourceIds->contains((int) $item['source_id']);
    }

    private function dataSectionForItem(ProfileSourceItem $item): string
    {
        return match ($item->type) {
            'summary' => 'me',
            'experience' => 'work',
            default => $item->type,
        };
    }

    /**
     * @return array<int, mixed>
     */
    private function listSection(mixed $value): array
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }

        if (! is_array($value)) {
            return [];
        }

        if (array_is_list($value)) {
            return $value;
        }

        return $value === [] ? [] : [$value];
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredData(ProfileSourceItem $item): array
    {
        $structuredData = $item->structured_data ?? [];

        if (! is_array($structuredData)) {
            return [];
        }

        $data = $structuredData['data'] ?? $structuredData;

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasAnyValue(array $data, array $keys): bool
    {
        foreach ($keys as $key) {
            if (filled($data[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function workFromStructuredData(array $data, ProfileSourceItem $item): array
    {
        return [
            'company' => $this->cleanText((string) ($data['company'] ?? $this->firstLine($item->content))),
            'role' => $this->cleanText((string) ($data['role'] ?? $item->title ?? 'Experience')),
            'start_date' => $this->cleanText((string) ($data['start_date'] ?? '')),
            'end_date' => $this->cleanText((string) ($data['end_date'] ?? '')),
            'date_range' => $this->cleanText((string) ($data['date_range'] ?? '')),
            'duration' => $this->cleanText((string) ($data['duration'] ?? '')),
            'location' => $this->cleanText((string) ($data['location'] ?? '')),
            'description' => $this->cleanText((string) ($data['description'] ?? $item->content)),
            'highlights' => $this->stringList($data['highlights'] ?? []),
            'technologies' => $this->stringList($data['technologies'] ?? []),
            'source' => 'cv',
            'source_id' => $item->profile_source_id,
            'source_item_id' => $item->id,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function legacyWorkItems(ProfileSourceItem $item): array
    {
        $profile = new Profile;
        $content = "LAST WORK EXPERIENCE\n".$item->content;
        $result = (new LocalProfileKnowledgeClient)->structureCv($profile, $content);
        $workItems = $result->items('work');

        if (count($workItems) <= 1) {
            return [];
        }

        return collect($workItems)
            ->map(fn (array $work): array => $this->workFromStructuredData($work, $item))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function projectFromStructuredData(array $data, ProfileSourceItem $item): array
    {
        return [
            'name' => $this->cleanText((string) ($data['name'] ?? $item->title ?? 'CV Project')),
            'description' => $this->cleanText((string) ($data['description'] ?? $item->content)),
            'url' => $this->cleanText((string) ($data['url'] ?? '')),
            'technologies' => $this->stringList($data['technologies'] ?? []),
            'source_work' => $this->cleanText((string) ($data['source_work'] ?? '')),
            'source' => 'cv',
            'source_id' => $item->profile_source_id,
            'source_item_id' => $item->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function skillFromStructuredData(array $data, ProfileSourceItem $item): array
    {
        return [
            'name' => $this->cleanText((string) ($data['name'] ?? $item->title ?? $item->content)),
            'category' => $this->cleanText((string) ($data['category'] ?? '')),
            'description' => $this->cleanText((string) ($data['description'] ?? '')),
            'source' => 'cv',
            'source_id' => $item->profile_source_id,
            'source_item_id' => $item->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function educationFromStructuredData(array $data, ProfileSourceItem $item): array
    {
        return [
            'institution' => $this->cleanText((string) ($data['institution'] ?? $item->title ?? '')),
            'degree' => $this->cleanText((string) ($data['degree'] ?? '')),
            'start_date' => $this->cleanText((string) ($data['start_date'] ?? '')),
            'end_date' => $this->cleanText((string) ($data['end_date'] ?? '')),
            'description' => $this->cleanText((string) ($data['description'] ?? $item->content)),
            'source' => 'cv',
            'source_id' => $item->profile_source_id,
            'source_item_id' => $item->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function achievementFromStructuredData(array $data, ProfileSourceItem $item): array
    {
        return [
            'name' => $this->cleanText((string) ($data['name'] ?? $item->title ?? 'Achievement')),
            'description' => $this->cleanText((string) ($data['description'] ?? $item->content)),
            'date' => $this->cleanText((string) ($data['date'] ?? '')),
            'source' => 'cv',
            'source_id' => $item->profile_source_id,
            'source_item_id' => $item->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function objectSection(mixed $value): array
    {
        if ($value instanceof \stdClass) {
            return (array) $value;
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<int, string>
     */
    private function contentLines(string $content): array
    {
        return collect(preg_split('/\R/u', $content) ?: [])
            ->map(fn (string $line): string => $this->cleanText($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->values()
            ->all();
    }

    private function firstLine(string $content): string
    {
        return $this->contentLines($content)[0] ?? '';
    }

    private function projectName(string $line): string
    {
        return Str::limit($this->cleanText(preg_split('/[.:;-]/u', $line)[0] ?? $line), 80, '');
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $values): array
    {
        if (is_string($values)) {
            $values = preg_split('/[,\n]+/u', $values) ?: [];
        }

        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->flatten()
            ->map(fn (mixed $value): string => $this->cleanText((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->values()
            ->all();
    }

    private function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? '';

        return trim($text);
    }
}
