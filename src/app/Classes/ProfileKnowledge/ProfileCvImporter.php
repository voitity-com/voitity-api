<?php

namespace App\Classes\ProfileKnowledge;

use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeAIService;
use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeStructure;
use App\Enums\ProfileFactVisibility;
use App\Enums\ProfileSourceStatus;
use App\Enums\ProfileSourceType;
use App\Models\Profile;
use App\Models\ProfileFact;
use App\Models\ProfileSource;
use App\Models\ProfileSourceItem;
use App\Models\User;
use App\Services\ProfileKnowledge\ProfileKnowledgeSourceDeduplicator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

class ProfileCvImporter
{
    private const PARSER_VERSION = 'cv-text-v3';

    public function __construct(
        private readonly ProfileKnowledgeAIService $profileKnowledgeAIService,
        private readonly ProfileKnowledgeSourceDeduplicator $sourceDeduplicator,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function import(Profile $profile, User $user, ?UploadedFile $file, ?string $text, ?string $name, array $metadata = []): ProfileSource
    {
        $providedText = $this->normalizeText((string) ($text ?? ''));
        $storedFile = $file
            ? $this->storeSourceFile($profile, $file)
            : ($providedText !== '' ? $this->storeTextSourceFile($profile, $providedText, $name) : null);
        $fileText = $file ? $this->extractTextFromFile($file) : '';
        $extractedText = $providedText !== '' ? $providedText : $this->normalizeText($fileText);
        $contentHash = $this->sourceDeduplicator->normalizedContentHash($extractedText);
        $this->sourceDeduplicator->synchronize($profile);
        $duplicate = $contentHash !== null
            ? $profile->sources()->where('content_hash', $contentHash)->oldest('id')->first()
            : null;
        $status = $duplicate
            ? ProfileSourceStatus::Duplicate
            : ($extractedText !== '' ? ProfileSourceStatus::PendingSync : ProfileSourceStatus::Failed);
        $structure = $extractedText !== '' && ! $duplicate
            ? $this->profileKnowledgeAIService->structureCv($profile, $extractedText)
            : null;

        return DB::transaction(function () use ($profile, $user, $file, $storedFile, $extractedText, $contentHash, $duplicate, $status, $name, $metadata, $structure): ProfileSource {
            $source = ProfileSource::withoutEvents(fn (): ProfileSource => ProfileSource::create([
                'profile_id' => $profile->id,
                'user_id' => $user->id,
                'type' => ProfileSourceType::Cv,
                'name' => $name ?: ($file?->getClientOriginalName() ?: 'Source'),
                'original_filename' => $file?->getClientOriginalName() ?: ($storedFile['original_filename'] ?? null),
                'mime_type' => $file?->getClientMimeType() ?: ($storedFile['mime_type'] ?? null),
                'storage_path' => $storedFile['path'] ?? null,
                'status' => $status,
                'processing_stage' => match ($status) {
                    ProfileSourceStatus::PendingSync => 'pending_sync',
                    ProfileSourceStatus::Duplicate => 'duplicate',
                    default => 'parsing',
                },
                'last_error' => match ($status) {
                    ProfileSourceStatus::Duplicate => 'This source contains the same information as an existing source.',
                    ProfileSourceStatus::Failed => 'No readable text could be extracted from the source.',
                    default => null,
                },
                'retry_count' => 0,
                'extracted_text' => $extractedText !== '' ? $extractedText : null,
                'parser_version' => self::PARSER_VERSION,
                'content_hash' => $contentHash,
                'duplicate_of_source_id' => $duplicate?->id,
                'metadata' => array_filter([
                    ...$metadata,
                    'text_provided' => filled($extractedText),
                    'file' => $storedFile,
                    'file_size' => $storedFile['size'] ?? null,
                    'structuring' => $structure ? [
                        'source' => $structure->source,
                        'status' => $structure->status,
                        'confidence' => $structure->confidence,
                        'request_url' => $structure->requestUrl,
                    ] : null,
                    'knowledge_index' => array_filter([
                        'content_available' => $contentHash !== null,
                        'duplicate_of_source_id' => $duplicate?->id,
                    ], fn ($value) => $value !== null),
                ], fn ($value) => $value !== null),
                'last_synced_at' => null,
            ]));

            if ($extractedText !== '' && ! $duplicate) {
                $this->createItemsAndFacts($source, $extractedText, $structure);
            }

            return $source->load(['items.facts']);
        });
    }

    /**
     * @return array{disk: string, path: string, folder: string, visibility: string, original_filename: string, mime_type: string|null, size: int|null}
     */
    private function storeSourceFile(Profile $profile, UploadedFile $file): array
    {
        $diskName = $this->sourceFilesDisk();
        $visibility = $this->sourceFilesVisibility();
        $folder = trim($this->sourceFilesFolder().'/'.$profile->id, '/');
        $path = $file->store($folder, [
            'disk' => $diskName,
            'visibility' => $visibility,
        ]);

        if (! is_string($path)) {
            throw new RuntimeException('Unable to store profile source file.');
        }

        return [
            'disk' => $diskName,
            'path' => $path,
            'folder' => $folder,
            'visibility' => $visibility,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ];
    }

    /**
     * @return array{disk: string, path: string, folder: string, visibility: string, original_filename: string, mime_type: string, size: int}
     */
    private function storeTextSourceFile(Profile $profile, string $text, ?string $name): array
    {
        $diskName = $this->sourceFilesDisk();
        $visibility = $this->sourceFilesVisibility();
        $folder = trim($this->sourceFilesFolder().'/'.$profile->id, '/');
        $path = $folder.'/'.Str::uuid().'.txt';
        $stored = Storage::disk($diskName)->put($path, $text, [
            'visibility' => $visibility,
        ]);

        if (! $stored) {
            throw new RuntimeException('Unable to store profile source text file.');
        }

        return [
            'disk' => $diskName,
            'path' => $path,
            'folder' => $folder,
            'visibility' => $visibility,
            'original_filename' => $this->textSourceFileName($name),
            'mime_type' => 'text/plain',
            'size' => strlen($text),
        ];
    }

    private function textSourceFileName(?string $name): string
    {
        $fileName = trim((string) $name);
        $fileName = preg_replace('/[\\/\\\\\x00-\x1F\x7F]+/u', '-', $fileName) ?? '';
        $fileName = trim($fileName, ". \t\n\r\0\x0B");

        if ($fileName === '') {
            $fileName = 'profile-source';
        }

        return Str::endsWith(Str::lower($fileName), '.txt') ? $fileName : $fileName.'.txt';
    }

    private function sourceFilesDisk(): string
    {
        return (string) config('profile-knowledge-ai.sources.disk', 'profiles');
    }

    private function sourceFilesFolder(): string
    {
        $folder = trim((string) config('profile-knowledge-ai.sources.folder', 'sources'), '/');

        return $folder !== '' ? $folder : 'sources';
    }

    private function sourceFilesVisibility(): string
    {
        return (string) config('profile-knowledge-ai.sources.visibility', 'private');
    }

    private function extractTextFromFile(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if (! $path || ! is_readable($path)) {
            return '';
        }

        $mimeType = (string) $file->getClientMimeType();
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($mimeType === 'application/pdf' || $extension === 'pdf') {
            $pdfText = $this->extractPdfText($path);

            if ($pdfText !== '') {
                return $pdfText;
            }
        }

        $raw = (string) file_get_contents($path);

        if (str_starts_with($mimeType, 'text/') || in_array($extension, ['txt', 'md'], true)) {
            return $this->normalizeText($raw);
        }

        return $this->extractBestEffortText($raw);
    }

    private function extractPdfText(string $path): string
    {
        try {
            $text = (new PdfParser)->parseFile($path)->getText();
            $text = $this->normalizeText($text);

            return str_word_count($text) >= 20 ? $text : '';
        } catch (Throwable) {
            return '';
        }
    }

    private function extractBestEffortText(string $raw): string
    {
        $text = preg_replace('/[^\P{C}\n\t ]+/u', ' ', $raw) ?? '';
        $text = preg_replace('/[^\x20-\x7E\x{00A0}-\x{024F}\n\t]+/u', ' ', $text) ?? '';
        $text = $this->normalizeText($text);

        return str_word_count($text) >= 20 ? $text : '';
    }

    private function createItemsAndFacts(ProfileSource $source, string $text, ?ProfileKnowledgeStructure $structure): void
    {
        $sections = $this->sectionsFromStructure($structure);

        if ($sections->isEmpty()) {
            $sections = $this->extractSections($text);
        }

        $sections->each(function (array $section) use ($source, $structure): void {
            /** @var ProfileSourceItem $item */
            $item = ProfileSourceItem::withoutEvents(fn (): ProfileSourceItem => $source->items()->create([
                'profile_id' => $source->profile_id,
                'type' => $section['category'],
                'title' => $section['title'],
                'content' => Str::limit($section['content'], 5000, ''),
                'structured_data' => array_filter([
                    'source' => 'cv',
                    'parser_version' => self::PARSER_VERSION,
                    'structuring_source' => $structure?->source,
                    'structuring_status' => $structure?->status,
                    'data' => $section['structured_data'] ?? null,
                ], fn ($value) => $value !== null),
                'confidence' => $section['confidence'],
                'approved' => false,
                'indexed' => false,
                'metadata' => [
                    'line_count' => $section['line_count'],
                ],
            ]));

            ProfileFact::withoutEvents(fn (): ProfileFact => $item->facts()->create([
                'profile_id' => $source->profile_id,
                'profile_source_id' => $source->id,
                'category' => $section['category'],
                'text' => Str::limit($section['content'], 1200, ''),
                'visibility' => ProfileFactVisibility::Public,
                'approved' => false,
                'indexed' => false,
                'metadata' => [
                    'title' => $section['title'],
                    'source_type' => ProfileSourceType::Cv->value,
                    'structuring_source' => $structure?->source,
                ],
            ]));
        });
    }

    /**
     * @return Collection<int, array{category: string, title: string, content: string, structured_data: array<string, mixed>, confidence: float, line_count: int}>
     */
    private function sectionsFromStructure(?ProfileKnowledgeStructure $structure): Collection
    {
        if (! $structure || ! $structure->isSuccessful() || ! $structure->hasData()) {
            return collect();
        }

        $sections = collect();

        if (filled($structure->data['summary'] ?? null)) {
            $content = (string) $structure->data['summary'];
            $sections->push([
                'category' => 'summary',
                'title' => 'Summary',
                'content' => $content,
                'structured_data' => ['summary' => $content],
                'confidence' => $structure->confidence ?? 0.75,
                'line_count' => $this->lineCount($content),
            ]);
        }

        foreach ($structure->items('work') as $work) {
            $content = $this->workContent($work);
            $sections->push([
                'category' => 'experience',
                'title' => $this->workTitle($work),
                'content' => $content,
                'structured_data' => $work,
                'confidence' => $structure->confidence ?? 0.82,
                'line_count' => $this->lineCount($content),
            ]);
        }

        foreach ($structure->items('projects') as $project) {
            $content = $this->projectContent($project);
            $sections->push([
                'category' => 'projects',
                'title' => (string) ($project['name'] ?? 'Project'),
                'content' => $content,
                'structured_data' => $project,
                'confidence' => $structure->confidence ?? 0.78,
                'line_count' => $this->lineCount($content),
            ]);
        }

        foreach ($structure->items('skills') as $skill) {
            $content = trim(implode("\n", array_filter([
                $skill['name'] ?? '',
                $skill['description'] ?? '',
            ])));

            $sections->push([
                'category' => 'skills',
                'title' => (string) ($skill['name'] ?? 'Skill'),
                'content' => $content,
                'structured_data' => $skill,
                'confidence' => $structure->confidence ?? 0.78,
                'line_count' => $this->lineCount($content),
            ]);
        }

        foreach ($structure->items('education') as $education) {
            $content = $this->educationContent($education);
            $sections->push([
                'category' => 'education',
                'title' => (string) (($education['institution'] ?? '') ?: 'Education'),
                'content' => $content,
                'structured_data' => $education,
                'confidence' => $structure->confidence ?? 0.78,
                'line_count' => $this->lineCount($content),
            ]);
        }

        foreach ($structure->items('achievements') as $achievement) {
            $content = trim(implode("\n", array_filter([
                $achievement['name'] ?? '',
                $achievement['description'] ?? '',
                $achievement['date'] ?? '',
            ])));

            $sections->push([
                'category' => 'achievements',
                'title' => (string) ($achievement['name'] ?? 'Achievement'),
                'content' => $content,
                'structured_data' => $achievement,
                'confidence' => $structure->confidence ?? 0.78,
                'line_count' => $this->lineCount($content),
            ]);
        }

        return $sections
            ->filter(fn (array $section): bool => trim((string) $section['content']) !== '')
            ->values();
    }

    /**
     * @param  array<string, mixed>  $work
     */
    private function workTitle(array $work): string
    {
        $company = trim((string) ($work['company'] ?? ''));
        $role = trim((string) ($work['role'] ?? ''));

        if ($company !== '' && $role !== '') {
            return $company.' - '.$role;
        }

        return $company ?: ($role ?: 'Experience');
    }

    /**
     * @param  array<string, mixed>  $work
     */
    private function workContent(array $work): string
    {
        return trim(implode("\n", array_filter([
            $work['company'] ?? '',
            $work['role'] ?? '',
            $work['duration'] ?? '',
            $work['date_range'] ?? '',
            $work['location'] ?? '',
            $work['description'] ?? '',
            implode("\n", $this->stringList($work['highlights'] ?? [])),
            $this->prefixedList('Technologies', $work['technologies'] ?? []),
        ])));
    }

    /**
     * @param  array<string, mixed>  $project
     */
    private function projectContent(array $project): string
    {
        return trim(implode("\n", array_filter([
            $project['name'] ?? '',
            $project['description'] ?? '',
            $project['url'] ?? '',
            $this->prefixedList('Technologies', $project['technologies'] ?? []),
        ])));
    }

    /**
     * @param  array<string, mixed>  $education
     */
    private function educationContent(array $education): string
    {
        return trim(implode("\n", array_filter([
            $education['institution'] ?? '',
            $education['degree'] ?? '',
            $education['start_date'] ?? '',
            $education['end_date'] ?? '',
            $education['description'] ?? '',
        ])));
    }

    private function prefixedList(string $prefix, mixed $values): string
    {
        $list = $this->stringList($values);

        return $list === [] ? '' : $prefix.': '.implode(', ', $list);
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
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->values()
            ->all();
    }

    private function lineCount(string $content): int
    {
        return max(1, count(preg_split('/\R/u', trim($content)) ?: []));
    }

    /**
     * @return Collection<int, array{category: string, title: string, content: string, confidence: float, line_count: int}>
     */
    private function extractSections(string $text): Collection
    {
        $text = $this->prepareTextForSectionExtraction($text);
        $sections = [];
        $currentCategory = 'summary';
        $currentTitle = 'Summary';

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $heading = $this->detectHeading($line);

            if ($heading !== null) {
                [$currentCategory, $currentTitle] = $heading;
                $sections[$currentCategory] ??= ['title' => $currentTitle, 'lines' => []];

                continue;
            }

            $sections[$currentCategory] ??= ['title' => $currentTitle, 'lines' => []];
            $sections[$currentCategory]['lines'][] = $line;
        }

        if ($sections === []) {
            $sections['summary'] = [
                'title' => 'Summary',
                'lines' => [$text],
            ];
        }

        return collect($sections)
            ->map(function (array $section, string $category): ?array {
                $lines = collect($section['lines'])
                    ->filter(fn (string $line) => $line !== '')
                    ->values();

                if ($lines->isEmpty()) {
                    return null;
                }

                if (str_starts_with($category, '_')) {
                    return null;
                }

                return [
                    'category' => $category,
                    'title' => $section['title'],
                    'content' => $lines->implode("\n"),
                    'confidence' => $category === 'summary' ? 0.55 : 0.75,
                    'line_count' => $lines->count(),
                ];
            })
            ->filter()
            ->pipe(fn (Collection $sections) => $this->appendDerivedProjectSection($sections, $text))
            ->values();
    }

    /**
     * @return null|array{0: string, 1: string}
     */
    private function detectHeading(string $line): ?array
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
            'portfolio' => ['portfolio', 'book', 'model portfolio', 'editorial', 'shoots', 'photoshoots'],
            'topics' => ['topics', 'temas', 'content', 'contenido'],
            'links' => ['links', 'enlaces', 'social', 'redes'],
            '_ignore' => ['address', 'contact', 'languages', 'references', 'referencias'],
        ];

        foreach ($headings as $category => $variants) {
            if (in_array($normalized, $variants, true)) {
                return [$category, Str::headline($category)];
            }
        }

        return null;
    }

    private function prepareTextForSectionExtraction(string $text): string
    {
        $headings = [
            'LAST WORK EXPERIENCE',
            'WORK EXPERIENCE',
            'AWARDS & RECOGNITIONS',
            'AWARDS AND RECOGNITIONS',
            'TECHNOLOGIES',
            'TECHNOLOGY',
            'EXPERIENCE',
            'EDUCATION',
            'PROJECTS',
            'SKILLS',
            'ADDRESS',
            'CONTACT',
            'LANGUAGES',
            'REFERENCES',
        ];

        usort($headings, fn (string $first, string $second): int => mb_strlen($second) <=> mb_strlen($first));

        $headingPattern = collect($headings)
            ->map(fn (string $heading): string => preg_quote($heading, '/'))
            ->implode('|');

        $text = preg_replace('/(?<!^)(?<!\n)\s+('.$headingPattern.')\s+/u', "\n$1\n", $text) ?? $text;

        return $this->normalizeText($text);
    }

    private function appendDerivedProjectSection(Collection $sections, string $text): Collection
    {
        if ($sections->contains(fn (array $section) => $section['category'] === 'projects')) {
            return $sections;
        }

        $projectLines = collect(preg_split('/\R/u', $text) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => $this->looksLikeProjectLine($line))
            ->unique()
            ->take(8)
            ->values();

        if ($projectLines->isEmpty()) {
            return $sections;
        }

        return $sections->push([
            'category' => 'projects',
            'title' => 'Projects',
            'content' => $projectLines->implode("\n"),
            'confidence' => 0.65,
            'line_count' => $projectLines->count(),
        ]);
    }

    private function looksLikeProjectLine(string $line): bool
    {
        $line = trim($line, " \t\n\r\0\x0B-*•●");

        if (mb_strlen($line) < 20 || mb_strlen($line) > 350) {
            return false;
        }

        return (bool) preg_match(
            '/\b(api|app|application|chatbot|integration|integrating|mobile|platform|project|system|tool|website|nutrifami|whooptag)\b/i',
            $line
        );
    }

    private function normalizeText(?string $text): string
    {
        if (! $text) {
            return '';
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace(["\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}"], ' ', $text);
        $text = str_replace(['●', '•'], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? '';
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';

        return trim($text);
    }
}
