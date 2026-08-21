<?php

namespace App\Models;

use App\Enums\BusinessSourceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BusinessSource extends Model
{
    protected $appends = [
        'download_available',
        'download_filename',
    ];

    protected $fillable = [
        'business_id', 'user_id', 'type', 'name', 'original_filename', 'mime_type', 'storage_path',
        'status', 'extracted_text', 'token_count', 'metadata', 'last_error', 'indexed_at',
    ];

    protected $casts = [
        'status' => BusinessSourceStatus::class,
        'token_count' => 'integer',
        'metadata' => 'array',
        'indexed_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(BusinessKnowledgeChunk::class);
    }

    public function downloadFilename(): string
    {
        $fallback = 'business-source-'.($this->id ?: 'text');

        if ($this->type === 'text') {
            return self::textFilename($this->original_filename ?: $this->name, $fallback);
        }

        return self::safeFilename($this->original_filename ?: $this->name, $fallback);
    }

    public function isDownloadAvailable(): bool
    {
        return filled($this->storage_path)
            || ($this->type === 'text' && filled($this->extracted_text));
    }

    public static function safeFilename(?string $filename, string $fallback = 'business-source'): string
    {
        $filename = trim((string) $filename);
        $filename = preg_replace('/[\/\\\\\x00-\x1F\x7F]+/u', '-', $filename) ?? '';
        $filename = trim($filename, ". \t\n\r\0\x0B");

        return $filename !== '' ? $filename : $fallback;
    }

    public static function textFilename(?string $filename, string $fallback = 'business-source'): string
    {
        $filename = self::safeFilename($filename, $fallback);

        return Str::endsWith(Str::lower($filename), '.txt') ? $filename : $filename.'.txt';
    }

    protected function getDownloadAvailableAttribute(): bool
    {
        return $this->isDownloadAvailable();
    }

    protected function getDownloadFilenameAttribute(): string
    {
        return $this->downloadFilename();
    }
}
