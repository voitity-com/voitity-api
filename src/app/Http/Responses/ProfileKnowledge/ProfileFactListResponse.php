<?php

namespace App\Http\Responses\ProfileKnowledge;

use App\Models\ProfileFact;
use Illuminate\Pagination\LengthAwarePaginator;

class ProfileFactListResponse
{
    public function __construct(private readonly LengthAwarePaginator $facts) {}

    public function toArray(): array
    {
        return [
            'facts' => $this->facts->getCollection()
                ->map(fn (ProfileFact $fact) => (new ProfileFactResponse($fact))->toArray())
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $this->facts->currentPage(),
                'per_page' => $this->facts->perPage(),
                'last_page' => $this->facts->lastPage(),
                'total' => $this->facts->total(),
            ],
        ];
    }
}
