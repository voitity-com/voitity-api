<?php

namespace App\Services\Business;

use App\Enums\BusinessSourceStatus;
use App\Models\Business;
use App\Models\BusinessSource;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

class BusinessSourceService
{
    public function __construct(private readonly BusinessUsageRecorder $usage) {}

    public function store(Business $business, User $user, string $name, ?UploadedFile $file, ?string $content): BusinessSource
    {
        $path = null;
        $mime = null;
        $filename = null;
        $type = 'text';

        if ($file) {
            $filename = $file->getClientOriginalName();
            $mime = $file->getMimeType();
            $type = strtolower($file->getClientOriginalExtension()) === 'pdf' ? 'pdf' : 'file';
            $path = $file->store("business-sources/{$business->id}", 'profiles');
            $content = $this->extract($file, $type);
        }

        $text = trim((string) $content);

        return DB::transaction(function () use ($business, $user, $name, $path, $mime, $filename, $type, $text): BusinessSource {
            $tokens = $this->usage->estimateTokens($text);
            $source = $business->sources()->create([
                'user_id' => $user->id,
                'type' => $type,
                'name' => $name,
                'original_filename' => $filename,
                'mime_type' => $mime,
                'storage_path' => $path,
                'status' => BusinessSourceStatus::Indexed,
                'extracted_text' => $text,
                'token_count' => $tokens,
                'indexed_at' => now(),
            ]);

            foreach ($this->chunks($text) as $index => $chunk) {
                $source->chunks()->create([
                    'business_id' => $business->id,
                    'chunk_key' => 'chunk-'.($index + 1),
                    'content' => $chunk,
                    'token_count' => $this->usage->estimateTokens($chunk),
                ]);
            }

            $this->usage->record([
                'business_id' => $business->id,
                'business_source_id' => $source->id,
                'event_type' => 'source_indexed',
                'input_tokens' => $tokens,
                'provider' => 'local',
            ]);

            return $source->load('chunks');
        });
    }

    public function delete(BusinessSource $source): void
    {
        if ($source->storage_path) {
            Storage::disk('profiles')->delete($source->storage_path);
        }
        $source->delete();
    }

    private function extract(UploadedFile $file, string $type): string
    {
        if ($type === 'pdf') {
            return (new Parser)->parseFile($file->getRealPath())->getText();
        }

        return (string) file_get_contents($file->getRealPath());
    }

    /** @return array<int, string> */
    private function chunks(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $paragraphs = preg_split('/\n\s*\n/u', $text) ?: [$text];
        $chunks = [];
        $current = '';
        foreach ($paragraphs as $paragraph) {
            $candidate = trim($current."\n\n".trim($paragraph));
            if (mb_strlen($candidate) > 2400 && $current !== '') {
                $chunks[] = $current;
                $current = trim($paragraph);
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
