<?php

namespace App\Http\Responses\Admin;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class AdminUserListResponse
{
    public function __construct(private readonly LengthAwarePaginator $users) {}

    public function toArray(): array
    {
        return [
            'users' => $this->users->getCollection()
                ->map(fn (User $user) => (new AdminUserResponse($user))->toArray())
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $this->users->currentPage(),
                'per_page' => $this->users->perPage(),
                'last_page' => $this->users->lastPage(),
                'total' => $this->users->total(),
            ],
        ];
    }
}
