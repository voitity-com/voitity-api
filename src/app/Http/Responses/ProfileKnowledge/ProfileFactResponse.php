<?php

namespace App\Http\Responses\ProfileKnowledge;

use App\Models\ProfileFact;

class ProfileFactResponse
{
    public function __construct(private readonly ProfileFact $fact) {}

    public function toArray(): array
    {
        return [
            'id' => $this->fact->id,
            'profile_id' => $this->fact->profile_id,
            'profile_source_id' => $this->fact->profile_source_id,
            'profile_source_item_id' => $this->fact->profile_source_item_id,
            'category' => $this->fact->category,
            'text' => $this->fact->text,
            'visibility' => $this->fact->visibility?->value,
            'approved' => (bool) $this->fact->approved,
            'indexed' => (bool) $this->fact->indexed,
            'metadata' => $this->fact->metadata,
            'created_at' => $this->fact->created_at?->toJSON(),
            'updated_at' => $this->fact->updated_at?->toJSON(),
        ];
    }
}
