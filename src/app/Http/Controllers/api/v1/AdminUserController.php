<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\Admin\AdminUserListResponse;
use App\Http\Responses\Admin\AdminUserResponse;
use App\Http\Responses\User\UserResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminUserController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (! $this->isAdmin($admin)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $perPage = $this->perPage($request);
        $search = trim((string) $request->query('search', ''));

        $users = User::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $term = '%'.mb_strtolower($search).'%';

                    $query
                        ->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(first_name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', [$term]);
                });
            })
            ->withCount($this->userCountRelations())
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'message' => 'Users retrieved successfully.',
            'data' => (new AdminUserListResponse($users))->toArray(),
        ], 200);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $admin = $request->user();

        if (! $this->isAdmin($admin)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $user
            ->loadCount($this->userCountRelations())
            ->load([
                'profiles' => fn ($query) => $query
                    ->withCount(['sources', 'avatars', 'voices', 'chats', 'aiImages', 'aiVideos'])
                    ->orderByDesc('created_at'),
            ]);

        return response()->json([
            'message' => 'User retrieved successfully.',
            'data' => (new AdminUserResponse($user, includeProfiles: true))->toArray(),
        ], 200);
    }

    public function impersonate(Request $request, User $user): JsonResponse
    {
        $admin = $request->user();

        if (! $this->isAdmin($admin)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ((int) $admin->id === (int) $user->id) {
            return response()->json(['message' => 'You can not impersonate your own user.'], 422);
        }

        $token = $user->createToken(
            'admin-impersonation',
            array_values(array_unique([...$user->getRoleAbilities(), 'user:read']))
        );

        Log::info('Admin user impersonation started.', [
            'admin_user_id' => $admin->id,
            'impersonated_user_id' => $user->id,
            'token_id' => $token->accessToken->id,
        ]);

        return response()->json([
            'message' => 'Impersonation token created successfully.',
            'data' => [
                'access_token' => $token->plainTextToken,
                'admin' => (new UserResponse($admin))->toArray(),
                'user' => (new UserResponse($user))->toArray(),
            ],
        ], 200);
    }

    public function stopImpersonation(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token) {
            if ($token->name !== 'admin-impersonation') {
                return response()->json(['message' => 'Current token is not an admin impersonation token.'], 422);
            }

            Log::info('Admin user impersonation stopped.', [
                'token_id' => $token->id,
                'user_id' => $user?->id,
            ]);

            $token->delete();
        }

        return response()->json([
            'message' => 'Impersonation stopped successfully.',
        ], 200);
    }

    private function isAdmin(mixed $user): bool
    {
        return $user instanceof User && $user->role === 'admin';
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);

        return min(max($perPage, 1), self::MAX_PER_PAGE);
    }

    /**
     * @return list<string>
     */
    private function userCountRelations(): array
    {
        return [
            'profiles',
            'voices',
            'profileAvatars',
            'aiImages',
            'aiVideos',
            'profileSources',
            'profileChats',
        ];
    }
}
