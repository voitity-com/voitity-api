<?php

namespace App\Classes\ProfileKnowledgeAIService;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ProfileKnowledgeStructure
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        public string $source,
        public string $status = 'pending',
        public array $data = [],
        public array $response = [],
        public ?string $requestUrl = null,
        public ?float $confidence = null
    ) {
        $this->data = self::normalizeData($this->data);
    }

    public function isSuccessful(): bool
    {
        return in_array($this->status, ['completed', 'success'], true);
    }

    public function isFailed(): bool
    {
        return in_array($this->status, ['failed', 'error'], true);
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'processing'], true);
    }

    public function hasData(): bool
    {
        return filled($this->data['summary'] ?? null)
            || $this->items('work') !== []
            || $this->items('projects') !== []
            || $this->items('skills') !== []
            || $this->items('education') !== []
            || $this->items('achievements') !== [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function items(string $section): array
    {
        $items = $this->data[$section] ?? [];

        return is_array($items) && array_is_list($items) ? $items : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'status' => $this->status,
            'data' => $this->data,
            'request_url' => $this->requestUrl,
            'response' => $this->response,
            'confidence' => $this->confidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeData(array $data): array
    {
        return [
            'summary' => self::stringValue($data['summary'] ?? $data['about'] ?? ''),
            'work' => self::listValue($data['work'] ?? $data['experience'] ?? [], fn (array $item): array => self::normalizeWork($item)),
            'projects' => self::listValue($data['projects'] ?? [], fn (array $item): array => self::normalizeProject($item)),
            'skills' => self::listValue($data['skills'] ?? [], fn (array $item): array => self::normalizeSkill($item)),
            'education' => self::listValue($data['education'] ?? [], fn (array $item): array => self::normalizeEducation($item)),
            'achievements' => self::listValue($data['achievements'] ?? $data['awards'] ?? [], fn (array $item): array => self::normalizeAchievement($item)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeWork(array $item): array
    {
        return [
            'company' => self::stringValue($item['company'] ?? $item['employer'] ?? $item['organization'] ?? ''),
            'role' => self::stringValue($item['role'] ?? $item['title'] ?? $item['position'] ?? ''),
            'start_date' => self::stringValue($item['start_date'] ?? $item['start'] ?? ''),
            'end_date' => self::stringValue($item['end_date'] ?? $item['end'] ?? ''),
            'date_range' => self::stringValue($item['date_range'] ?? $item['dates'] ?? ''),
            'duration' => self::stringValue($item['duration'] ?? ''),
            'location' => self::stringValue($item['location'] ?? ''),
            'description' => self::stringValue($item['description'] ?? $item['summary'] ?? ''),
            'highlights' => self::stringList($item['highlights'] ?? $item['responsibilities'] ?? []),
            'technologies' => self::stringList($item['technologies'] ?? $item['skills'] ?? []),
            'source' => 'cv',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeProject(array $item): array
    {
        return [
            'name' => self::stringValue($item['name'] ?? $item['title'] ?? ''),
            'description' => self::stringValue($item['description'] ?? $item['summary'] ?? ''),
            'url' => self::stringValue($item['url'] ?? ''),
            'technologies' => self::stringList($item['technologies'] ?? $item['skills'] ?? []),
            'source_work' => self::stringValue($item['source_work'] ?? $item['company'] ?? ''),
            'source' => 'cv',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeSkill(array $item): array
    {
        return [
            'name' => self::stringValue($item['name'] ?? $item['skill'] ?? ''),
            'category' => self::stringValue($item['category'] ?? ''),
            'description' => self::stringValue($item['description'] ?? ''),
            'source' => 'cv',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeEducation(array $item): array
    {
        return [
            'institution' => self::stringValue($item['institution'] ?? $item['school'] ?? $item['organization'] ?? ''),
            'degree' => self::stringValue($item['degree'] ?? $item['title'] ?? ''),
            'start_date' => self::stringValue($item['start_date'] ?? $item['start'] ?? ''),
            'end_date' => self::stringValue($item['end_date'] ?? $item['end'] ?? ''),
            'description' => self::stringValue($item['description'] ?? ''),
            'source' => 'cv',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeAchievement(array $item): array
    {
        return [
            'name' => self::stringValue($item['name'] ?? $item['title'] ?? ''),
            'description' => self::stringValue($item['description'] ?? $item['summary'] ?? ''),
            'date' => self::stringValue($item['date'] ?? ''),
            'source' => 'cv',
        ];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $normalizer
     * @return array<int, array<string, mixed>>
     */
    private static function listValue(mixed $value, callable $normalizer): array
    {
        if (is_string($value)) {
            $value = collect(preg_split('/[\n,]+/u', $value) ?: [])
                ->map(fn (string $item): array => ['name' => trim($item)])
                ->all();
        }

        if (! is_array($value)) {
            return [];
        }

        if (! array_is_list($value)) {
            $value = [$value];
        }

        return collect($value)
            ->map(function (mixed $item) use ($normalizer): array {
                if (is_string($item)) {
                    $item = ['name' => $item, 'description' => $item];
                }

                return $normalizer(is_array($item) ? $item : []);
            })
            ->filter(fn (array $item): bool => collect($item)
                ->except(['source', 'highlights', 'technologies'])
                ->filter(fn (mixed $value): bool => filled($value))
                ->isNotEmpty())
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,\n]+/u', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return collect(Arr::flatten($value))
            ->map(fn (mixed $item): string => self::stringValue($item))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique(fn (string $item): string => Str::lower($item))
            ->values()
            ->all();
    }

    private static function stringValue(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        return trim(preg_replace('/[ \t]+/', ' ', (string) $value) ?? '');
    }
}
