<?php

namespace App\Http\Responses\ProfileKnowledge;

use App\Models\ProfileSourceItem;

class ProfileSourceItemResponse
{
    public function __construct(private readonly ProfileSourceItem $item) {}

    public function toArray(): array
    {
        return [
            'id' => $this->item->id,
            'profile_source_id' => $this->item->profile_source_id,
            'profile_id' => $this->item->profile_id,
            'type' => $this->item->type,
            'title' => $this->item->title,
            'content' => $this->item->content,
            'structured_data' => $this->item->structured_data,
            'confidence' => (float) $this->item->confidence,
            'approved' => (bool) $this->item->approved,
            'indexed' => (bool) $this->item->indexed,
            'source_url' => $this->item->source_url,
            'metadata' => $this->item->metadata,
            'facts' => $this->item->relationLoaded('facts')
                ? $this->item->facts
                    ->map(fn ($fact) => (new ProfileFactResponse($fact))->toArray())
                    ->values()
                    ->all()
                : [],
            'created_at' => $this->item->created_at?->toJSON(),
            'updated_at' => $this->item->updated_at?->toJSON(),
        ];
    }
}
