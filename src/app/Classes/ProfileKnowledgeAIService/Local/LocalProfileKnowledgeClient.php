<?php

namespace App\Classes\ProfileKnowledgeAIService\Local;

use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeAIClient;
use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeStructure;
use App\Models\Profile;
use Illuminate\Support\Str;

class LocalProfileKnowledgeClient implements ProfileKnowledgeAIClient
{
    private const ROLE_WORDS = [
        'developer',
        'engineer',
        'architect',
        'lead',
        'manager',
        'designer',
        'analyst',
        'consultant',
        'director',
        'specialist',
        'administrator',
        'desarrollador',
        'ingeniero',
        'programador',
        'arquitecto',
        'lider',
        'analista',
        'consultor',
        'model',
        'creator',
    ];

    public function structureCv(Profile $profile, string $text): ProfileKnowledgeStructure
    {
        $normalizedText = $this->normalizeText($text);
        $sections = $this->sections($normalizedText);
        $experienceText = $sections['experience'] ?? $normalizedText;

        $data = [
            'summary' => $this->summary($sections, $normalizedText),
            'work' => $this->workItems($experienceText),
            'projects' => $this->projectItems($sections['projects'] ?? '', $experienceText),
            'skills' => $this->skillItems($sections['skills'] ?? ''),
            'education' => $this->educationItems($sections['education'] ?? ''),
            'achievements' => $this->achievementItems($sections['achievements'] ?? ''),
        ];

        return new ProfileKnowledgeStructure(
            source: 'local',
            status: 'success',
            data: $data,
            response: ['parser' => 'local-cv-structure-v1'],
            confidence: $data['work'] !== [] ? 0.72 : 0.55
        );
    }

    /**
     * @return array<string, string>
     */
    private function sections(string $text): array
    {
        $sections = [];
        $current = 'summary';

        foreach ($this->lines($this->prepareHeadings($text)) as $line) {
            $heading = $this->heading($line);

            if ($heading !== null) {
                $current = $heading;
                $sections[$current] ??= [];

                continue;
            }

            if ($current === '_ignore') {
                continue;
            }

            $sections[$current] ??= [];
            $sections[$current][] = $line;
        }

        return collect($sections)
            ->map(fn (array $lines): string => implode("\n", $lines))
            ->filter(fn (string $section): bool => trim($section) !== '')
            ->all();
    }

    /**
     * @param  array<string, string>  $sections
     */
    private function summary(array $sections, string $text): string
    {
        if (filled($sections['summary'] ?? null)) {
            return Str::limit($sections['summary'], 1200, '');
        }

        return collect($this->lines($text))
            ->reject(fn (string $line): bool => $this->heading($line) !== null)
            ->filter(fn (string $line): bool => ! $this->looksLikeContactLine($line))
            ->take(4)
            ->implode("\n");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function workItems(string $text): array
    {
        $lines = $this->lines($text);
        $items = [];
        $current = null;

        for ($index = 0; $index < count($lines); $index++) {
            $line = $lines[$index];
            $next = $lines[$index + 1] ?? null;

            if ($next !== null && $this->looksLikeCompanyLine($line) && $this->looksLikeRoleLine($next)) {
                if ($current !== null) {
                    $items[] = $this->finalizeWorkItem($current);
                }

                $current = [
                    'company' => $this->cleanCompany($line),
                    'role' => $this->cleanRole($next),
                    'details' => [],
                ];
                $index++;

                continue;
            }

            if ($current !== null) {
                $current['details'][] = $line;
            }
        }

        if ($current !== null) {
            $items[] = $this->finalizeWorkItem($current);
        }

        if ($items !== []) {
            return $items;
        }

        return [[
            'company' => $this->lines($text)[0] ?? 'CV',
            'role' => 'Experience',
            'description' => $text,
            'source' => 'cv',
        ]];
    }

    /**
     * @param  array{company:string, role:string, details:array<int, string>}  $item
     * @return array<string, mixed>
     */
    private function finalizeWorkItem(array $item): array
    {
        $details = collect($item['details']);
        $duration = $details->first(fn (string $line): bool => $this->looksLikeDurationLine($line)) ?: '';
        $dateRange = $details->first(fn (string $line): bool => $this->looksLikeDateRangeLine($line)) ?: '';
        $location = $details->first(fn (string $line): bool => $this->looksLikeLocationLine($line)) ?: '';
        $descriptionLines = $details
            ->reject(fn (string $line): bool => $line === $duration || $line === $dateRange || $line === $location)
            ->values();

        return [
            'company' => $item['company'],
            'role' => $item['role'],
            'date_range' => $dateRange,
            'duration' => $duration,
            'location' => $location,
            'description' => $descriptionLines->implode("\n"),
            'highlights' => $descriptionLines->take(5)->all(),
            'technologies' => $this->technologiesFromText($descriptionLines->implode(' ')),
            'source' => 'cv',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function projectItems(string $projectsText, string $experienceText): array
    {
        $explicitProjectLines = collect($this->lines($projectsText))
            ->filter(fn (string $line): bool => mb_strlen($line) >= 10);
        $derivedProjectLines = collect($this->lines($experienceText))
            ->filter(fn (string $line): bool => $this->looksLikeProjectLine($line))
            ->reject(fn (string $line): bool => (bool) preg_match('/\s+Website$/i', $line));

        $projectLines = collect([
            ...$explicitProjectLines,
            ...$derivedProjectLines,
        ])
            ->map(fn (string $line): string => trim($line, " \t\n\r\0\x0B-*"))
            ->unique(fn (string $line): string => Str::lower($line))
            ->take(12)
            ->values();

        return $projectLines
            ->map(fn (string $line): array => [
                'name' => $this->projectName($line),
                'description' => $line,
                'technologies' => $this->technologiesFromText($line),
                'source' => 'cv',
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function skillItems(string $text): array
    {
        return collect(preg_split('/[,\n|]+/u', $text) ?: [])
            ->map(fn (string $skill): string => trim($skill, " \t\n\r\0\x0B.-"))
            ->filter(fn (string $skill): bool => $skill !== '' && mb_strlen($skill) <= 80)
            ->unique(fn (string $skill): string => Str::lower($skill))
            ->map(fn (string $skill): array => [
                'name' => $skill,
                'category' => '',
                'description' => '',
                'source' => 'cv',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function educationItems(string $text): array
    {
        $lines = $this->lines($text);

        if ($lines === []) {
            return [];
        }

        return [[
            'institution' => $lines[0] ?? '',
            'degree' => $lines[1] ?? '',
            'description' => implode("\n", $lines),
            'source' => 'cv',
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function achievementItems(string $text): array
    {
        return collect($this->lines($text))
            ->filter(fn (string $line): bool => mb_strlen($line) >= 10)
            ->map(fn (string $line): array => [
                'name' => $this->projectName($line),
                'description' => $line,
                'source' => 'cv',
            ])
            ->values()
            ->all();
    }

    private function prepareHeadings(string $text): string
    {
        $headings = [
            'LAST WORK EXPERIENCE',
            'WORK EXPERIENCE',
            'AWARDS & RECOGNITIONS',
            'AWARDS AND RECOGNITIONS',
            'TECHNOLOGIES',
            'TECHNOLOGY',
            'TECNOLOGIAS',
            'TECNOLOGÍAS',
            'EXPERIENCE',
            'EXPERIENCIA',
            'EDUCATION',
            'EDUCACION',
            'EDUCACIÓN',
            'FORMACION',
            'FORMACIÓN',
            'PROJECTS',
            'PROYECTOS',
            'SKILLS',
            'HABILIDADES',
            'ADDRESS',
            'DIRECCION',
            'DIRECCIÓN',
            'CONTACT',
            'CONTACTO',
            'LANGUAGES',
            'IDIOMAS',
            'REFERENCES',
            'REFERENCIAS',
        ];

        usort($headings, fn (string $first, string $second): int => mb_strlen($second) <=> mb_strlen($first));

        $pattern = collect($headings)
            ->map(fn (string $heading): string => preg_quote($heading, '/'))
            ->implode('|');

        return preg_replace('/(?<!^)(?<!\n)\s+('.$pattern.')\s+/u', "\n$1\n", $text) ?? $text;
    }

    private function heading(string $line): ?string
    {
        $normalized = Str::of($line)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9 ]/', ' ')
            ->squish()
            ->toString();

        if (strlen($normalized) > 80) {
            return null;
        }

        $headings = [
            'experience' => ['experience', 'experiencia', 'work experience', 'last work experience', 'employment', 'historial laboral', 'trabajo'],
            'education' => ['education', 'educacion', 'formacion', 'studies', 'estudios'],
            'projects' => ['projects', 'proyectos', 'portfolio projects'],
            'skills' => ['skills', 'habilidades', 'technologies', 'technology', 'tecnologias', 'stack'],
            'achievements' => ['awards', 'awards recognitions', 'awards and recognitions', 'reconocimientos', 'premios'],
            '_ignore' => ['address', 'direccion', 'contact', 'contacto', 'languages', 'idiomas', 'references', 'referencias'],
        ];

        foreach ($headings as $category => $variants) {
            if (in_array($normalized, $variants, true)) {
                return $category;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function lines(string $text): array
    {
        return collect(preg_split('/\R/u', $text) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->values()
            ->all();
    }

    private function looksLikeCompanyLine(string $line): bool
    {
        if (
            mb_strlen($line) > 120
            || $this->looksLikeDateRangeLine($line)
            || $this->looksLikeDurationLine($line)
            || $this->looksLikeLocationLine($line)
        ) {
            return false;
        }

        if (($this->looksLikeProjectLine($line) && ! preg_match('/\s+Website$/i', $line)) || $this->looksLikeContactLine($line)) {
            return false;
        }

        return ! $this->looksLikeRoleLine($line);
    }

    private function looksLikeRoleLine(string $line): bool
    {
        $normalized = Str::of($line)->lower()->ascii()->toString();

        if (mb_strlen($line) > 90) {
            return false;
        }

        return collect(self::ROLE_WORDS)->contains(fn (string $word): bool => str_contains($normalized, $word));
    }

    private function looksLikeDurationLine(string $line): bool
    {
        return (bool) preg_match('/\b(less than\s+)?\d+(\.\d+)?\s*(year|years|yr|yrs|month|months)\b/i', $line);
    }

    private function looksLikeDateRangeLine(string $line): bool
    {
        return (bool) preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\s+\d{4}\s*[-–]\s*(current|present|today|[a-z]+\s+\d{4})\b/i', $line);
    }

    private function looksLikeLocationLine(string $line): bool
    {
        return Str::lower($line) === 'remote' || (bool) preg_match('/^[A-Z][A-Za-z .]+,\s*[A-Z][A-Za-z .]+$/', $line);
    }

    private function looksLikeProjectLine(string $line): bool
    {
        if (mb_strlen($line) < 20 || mb_strlen($line) > 350) {
            return false;
        }

        return (bool) preg_match('/\b(api|app|application|chatbot|integration|integrating|mobile|platform|project|system|tool|website|nutrifami|whooptag)\b/i', $line);
    }

    private function looksLikeContactLine(string $line): bool
    {
        return str_contains($line, '@') || (bool) preg_match('/\bhttps?:\/\/|\+\d{2,}/i', $line);
    }

    private function cleanCompany(string $line): string
    {
        return trim(preg_replace('/\s+Website$/i', '', $line) ?? $line);
    }

    private function cleanRole(string $line): string
    {
        $role = Str::headline(Str::lower($line));

        return str_replace(
            ['Php', 'Api', 'Ai', 'Ui', 'Ux', 'Tdd'],
            ['PHP', 'API', 'AI', 'UI', 'UX', 'TDD'],
            $role
        );
    }

    /**
     * @return array<int, string>
     */
    private function technologiesFromText(string $text): array
    {
        $known = [
            'PHP',
            'Laravel',
            'Vue.js',
            'React',
            'TypeScript',
            'JavaScript',
            'PostgreSQL',
            'MongoDB',
            'Apache Kafka',
            'Kafka',
            'Azure',
            'OpenAI API',
            'OpenAI',
            'ElevenLabs',
            'Twilio',
            'Football API',
        ];

        return collect($known)
            ->filter(fn (string $technology): bool => Str::contains($text, $technology, true))
            ->unique(fn (string $technology): string => Str::lower($technology))
            ->values()
            ->all();
    }

    private function projectName(string $line): string
    {
        return Str::limit(trim(preg_split('/[.:;-]/u', $line)[0] ?? $line), 80, '');
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace(["\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}"], ' ', $text);
        $text = str_replace(['●', '•'], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? '';
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';

        return trim($text);
    }
}
