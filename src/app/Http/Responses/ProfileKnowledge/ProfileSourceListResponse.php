<?php

namespace App\Http\Responses\ProfileKnowledge;

use App\Models\ProfileSource;
use Illuminate\Pagination\LengthAwarePaginator;

class ProfileSourceListResponse
{
    public function __construct(private readonly LengthAwarePaginator $sources) {}

    public function toArray(): array
    {
        return [
            'sources' => $this->sources->getCollection()
                ->map(fn (ProfileSource $source) => (new ProfileSourceResponse($source))->toArray())
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $this->sources->currentPage(),
                'per_page' => $this->sources->perPage(),
                'last_page' => $this->sources->lastPage(),
                'total' => $this->sources->total(),
            ],
        ];
    }
}
