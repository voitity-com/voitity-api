<?php

namespace App\Classes\ProfileKnowledge;

use App\Enums\ProfileSourceStatus;
use App\Models\Profile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class ProfileQualityAnalyzer
{
    /**
     * @return array<string, mixed>
     */
    public function analyze(Profile $profile): array
    {
        $templates = config('profile-professions.templates', []);
        $professionKey = 'custom';
        $template = $templates['custom'] ?? [];
        $checks = collect($template['quality_rules'] ?? [])
            ->map(fn (array $rule) => $this->evaluateRule($profile, $rule))
            ->values();

        $totalWeight = max(1, $checks->sum('weight'));
        $completedWeight = $checks
            ->filter(fn (array $check) => (bool) $check['passed'])
            ->sum('weight');

        return [
            'profile_id' => $profile->id,
            'profession' => [
                'key' => $professionKey,
                'label' => $template['label'] ?? 'General',
                'template_version' => config('profile-professions.version'),
            ],
            'score' => (int) round(($completedWeight / $totalWeight) * 100),
            'completed_weight' => $completedWeight,
            'total_weight' => $totalWeight,
            'checks' => $checks->all(),
            'counts' => [
                'sources' => $profile->sources()->count(),
                'approved_sources' => $profile->sources()
                    ->whereIn('status', [ProfileSourceStatus::Approved->value, ProfileSourceStatus::Indexed->value])
                    ->count(),
                'facts' => $profile->facts()->count(),
                'approved_facts' => $profile->facts()->where('approved', true)->count(),
                'indexed_facts' => $profile->facts()->where('indexed', true)->count(),
                'networks' => count((array) ($profile->networks ?? [])),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
    private function evaluateRule(Profile $profile, array $rule): array
    {
        $type = (string) ($rule['type'] ?? '');
        $actual = 0;
        $passed = false;

        if ($type === 'profile_field') {
            $field = (string) ($rule['field'] ?? '');
            $actual = mb_strlen(trim((string) ($profile->{$field} ?? '')));
            $passed = $actual >= (int) ($rule['min_length'] ?? 1);
        } elseif ($type === 'data_section') {
            $actual = $this->countDataSection($profile->data ?? [], (string) ($rule['section'] ?? ''));
            $passed = $actual >= (int) ($rule['min_items'] ?? 1);
        } elseif ($type === 'fact_category') {
            $actual = $profile->facts()
                ->where('category', (string) ($rule['category'] ?? ''))
                ->where('approved', true)
                ->count();
            $passed = $actual >= (int) ($rule['min_items'] ?? 1);
        } elseif ($type === 'source_type') {
            $actual = $profile->sources()
                ->where('type', (string) ($rule['source_type'] ?? ''))
                ->whereIn('status', [ProfileSourceStatus::Approved->value, ProfileSourceStatus::Indexed->value])
                ->count();
            $passed = $actual >= (int) ($rule['min_items'] ?? 1);
        } elseif ($type === 'network') {
            $network = (string) ($rule['network'] ?? '');
            $actual = filled(Arr::get((array) ($profile->networks ?? []), $network)) ? 1 : 0;
            $passed = $actual === 1;
        } elseif ($type === 'network_any') {
            $actual = count(array_filter((array) ($profile->networks ?? []), fn ($value) => filled($value)));
            $passed = $actual > 0;
        }

        return [
            'key' => $rule['key'] ?? $type,
            'label' => $rule['label'] ?? $rule['key'] ?? $type,
            'type' => $type,
            'passed' => $passed,
            'actual' => $actual,
            'required' => $rule['min_length'] ?? $rule['min_items'] ?? 1,
            'weight' => (int) ($rule['weight'] ?? 1),
        ];
    }

    private function countDataSection(array $data, string $section): int
    {
        $value = $data[$section] ?? null;

        if ($value === null) {
            return 0;
        }

        if (is_string($value)) {
            return filled($value) ? 1 : 0;
        }

        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return Collection::make($value)
                    ->filter(fn ($item) => filled($item))
                    ->count();
            }

            return Collection::make($value)
                ->filter(fn ($item) => filled($item))
                ->count();
        }

        return filled($value) ? 1 : 0;
    }
}
