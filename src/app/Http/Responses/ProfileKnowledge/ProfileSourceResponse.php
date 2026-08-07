<?php

namespace App\Http\Responses\ProfileKnowledge;

use App\Models\ProfileSource;

class ProfileSourceResponse
{
    public function __construct(private readonly ProfileSource $source) {}

    public function toArray(): array
    {
        return [
            'id' => $this->source->id,
            'profile_id' => $this->source->profile_id,
            'user_id' => $this->source->user_id,
            'type' => $this->source->type?->value,
            'name' => $this->source->name,
            'original_filename' => $this->source->original_filename,
            'mime_type' => $this->source->mime_type,
            'storage_path' => $this->source->storage_path,
            'file' => [
                'available' => filled($this->source->storage_path),
                'name' => $this->source->original_filename ?: $this->source->name,
                'mime_type' => $this->source->mime_type,
                'size' => data_get($this->source->metadata, 'file.size', data_get($this->source->metadata, 'file_size')),
            ],
            'status' => $this->source->status?->value,
            'extracted_text' => $this->source->extracted_text,
            'parser_version' => $this->source->parser_version,
            'duplicate_of_source_id' => $this->source->duplicate_of_source_id,
            'metadata' => $this->source->metadata,
            'last_synced_at' => $this->source->last_synced_at?->toJSON(),
            'approved_at' => $this->source->approved_at?->toJSON(),
            'indexed_at' => $this->source->indexed_at?->toJSON(),
            'items' => $this->source->relationLoaded('items')
                ? $this->source->items
                    ->map(fn ($item) => (new ProfileSourceItemResponse($item))->toArray())
                    ->values()
                    ->all()
                : [],
            'created_at' => $this->source->created_at?->toJSON(),
            'updated_at' => $this->source->updated_at?->toJSON(),
        ];
    }
}
