<?php

namespace App\Services\Business;

use App\Enums\BusinessSourceStatus;
use App\Jobs\Business\IndexBusinessSource;
use App\Models\Business;
use App\Models\BusinessSource;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
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
            if (! is_string($path)) {
                throw new RuntimeException('Unable to store business source file.');
            }
            $content = $this->extract($file, $type);
        }

        $text = trim((string) $content);

        if (! $file) {
            $filename = BusinessSource::textFilename($name);
            $mime = 'text/plain';
            $path = "business-sources/{$business->id}/".Str::uuid().'.txt';

            if (! Storage::disk('profiles')->put($path, $text)) {
                throw new RuntimeException('Unable to store business source text file.');
            }
        }

        $source = DB::transaction(function () use ($business, $user, $name, $path, $mime, $filename, $type, $text): BusinessSource {
            $tokens = $this->usage->estimateTokens($text);
            $source = $business->sources()->create([
                'user_id' => $user->id,
                'type' => $type,
                'name' => $name,
                'original_filename' => $filename,
                'mime_type' => $mime,
                'storage_path' => $path,
                'status' => BusinessSourceStatus::Processing,
                'extracted_text' => $text,
                'token_count' => $tokens,
                'indexed_at' => null,
            ]);

            return $source;
        });

        IndexBusinessSource::dispatch($source->id);

        return $source->refresh()->load('chunks');
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
}
