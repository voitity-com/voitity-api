<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\ProfileKnowledge\ProfileCvImporter;
use App\Classes\ProfileKnowledge\ProfileDataSynchronizer;
use App\Classes\ProfileKnowledge\ProfileQualityAnalyzer;
use App\Enums\ProfileSourceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileKnowledge\StoreProfileCvSourceRequest;
use App\Http\Requests\ProfileKnowledge\UpdateProfileFactRequest;
use App\Http\Responses\ProfileKnowledge\ProfileFactListResponse;
use App\Http\Responses\ProfileKnowledge\ProfileFactResponse;
use App\Http\Responses\ProfileKnowledge\ProfileSourceListResponse;
use App\Http\Responses\ProfileKnowledge\ProfileSourceResponse;
use App\Models\Profile;
use App\Models\ProfileFact;
use App\Models\ProfileSource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileKnowledgeController extends Controller
{
    public function professions(Request $request): JsonResponse
    {
        if (! $request->user()) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $templates = config('profile-professions.templates', []);

        return response()->json([
            'message' => 'Profile professions retrieved successfully.',
            'data' => [
                'default' => config('profile-professions.default', 'custom'),
                'version' => config('profile-professions.version'),
                'professions' => collect($templates)->values()->all(),
            ],
        ]);
    }

    public function sources(Request $request, Profile $profile): JsonResponse
    {
        if ($response = $this->authorizeProfileAccess($request, $profile)) {
            return $response;
        }

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'type' => ['nullable', 'string', 'max:40'],
        ]);

        $sources = $profile->sources()
            ->with(['items.facts'])
            ->when($validated['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->latest()
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'message' => 'Profile sources retrieved successfully.',
            'data' => (new ProfileSourceListResponse($sources))->toArray(),
        ]);
    }

    public function sourceFile(Request $request, Profile $profile, ProfileSource $source): JsonResponse|StreamedResponse
    {
        if ($response = $this->authorizeProfileAccess($request, $profile)) {
            return $response;
        }

        if ((int) $source->profile_id !== (int) $profile->id) {
            return response()->json(['message' => 'Profile source not found.'], 404);
        }

        $path = trim((string) $source->storage_path);

        if ($path === '') {
            return response()->json(['message' => 'Profile source file not found.'], 404);
        }

        $diskName = $this->resolveSourceFileDisk($source, $path);

        if (! $diskName) {
            return response()->json(['message' => 'Profile source file not found.'], 404);
        }

        $headers = array_filter([
            'Content-Type' => $source->mime_type,
        ]);

        return Storage::disk($diskName)->response($path, $this->sourceFileName($source), $headers, 'inline');
    }

    public function storeCv(
        StoreProfileCvSourceRequest $request,
        Profile $profile,
        ProfileCvImporter $importer
    ): JsonResponse {
        if ($response = $this->authorizeProfileAccess($request, $profile)) {
            return $response;
        }

        try {
            $source = $importer->import(
                profile: $profile,
                user: $request->user(),
                file: $request->file('file'),
                text: $request->validated('text'),
                name: $request->validated('name'),
                metadata: $request->validated('metadata') ?? []
            );

            return response()->json([
                'message' => 'CV source imported successfully.',
                'data' => (new ProfileSourceResponse($source))->toArray(),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Error importing profile CV source.', [
                'profile_id' => $profile->id,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function approveSource(
        Request $request,
        Profile $profile,
        ProfileSource $source,
        ProfileDataSynchronizer $synchronizer
    ): JsonResponse {
        if ($response = $this->authorizeProfileAccess($request, $profile)) {
            return $response;
        }

        if ((int) $source->profile_id !== (int) $profile->id) {
            return response()->json(['message' => 'Profile source not found.'], 404);
        }

        $source = DB::transaction(function () use ($profile, $source, $synchronizer): ProfileSource {
            $now = now();

            $source->update([
                'status' => ProfileSourceStatus::Indexed,
                'approved_at' => $now,
                'indexed_at' => $now,
            ]);

            $source->items()->update([
                'approved' => true,
                'indexed' => true,
            ]);

            $source->facts()->update([
                'approved' => true,
                'indexed' => true,
            ]);

            $synchronizer->syncApprovedSource($profile, $source->fresh(['items']));

            return $source->fresh(['items.facts']);
        });

        return response()->json([
            'message' => 'Profile source approved successfully.',
            'data' => (new ProfileSourceResponse($source))->toArray(),
        ]);
    }

    public function facts(Request $request, Profile $profile): JsonResponse
    {
        if ($response = $this->authorizeProfileAccess($request, $profile)) {
            return $response;
        }

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'category' => ['nullable', 'string', 'max:80'],
            'approved' => ['nullable', 'boolean'],
            'indexed' => ['nullable', 'boolean'],
        ]);

        $facts = $profile->facts()
            ->when($validated['category'] ?? null, fn ($query, string $category) => $query->where('category', $category))
            ->when($request->has('approved'), fn ($query) => $query->where('approved', $request->boolean('approved')))
            ->when($request->has('indexed'), fn ($query) => $query->where('indexed', $request->boolean('indexed')))
            ->latest()
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'message' => 'Profile facts retrieved successfully.',
            'data' => (new ProfileFactListResponse($facts))->toArray(),
        ]);
    }

    public function updateFact(UpdateProfileFactRequest $request, Profile $profile, ProfileFact $fact): JsonResponse
    {
        if ($response = $this->authorizeProfileAccess($request, $profile)) {
            return $response;
        }

        if ((int) $fact->profile_id !== (int) $profile->id) {
            return response()->json(['message' => 'Profile fact not found.'], 404);
        }

        $validated = $request->validated();

        if (($validated['indexed'] ?? false) === true) {
            $validated['approved'] = true;
        }

        $fact->update($validated);

        return response()->json([
            'message' => 'Profile fact updated successfully.',
            'data' => (new ProfileFactResponse($fact->fresh()))->toArray(),
        ]);
    }

    public function quality(Request $request, Profile $profile, ProfileQualityAnalyzer $analyzer): JsonResponse
    {
        if ($response = $this->authorizeProfileAccess($request, $profile)) {
            return $response;
        }

        return response()->json([
            'message' => 'Profile quality retrieved successfully.',
            'data' => $analyzer->analyze($profile),
        ]);
    }

    private function authorizeProfileAccess(Request $request, Profile $profile): ?JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->role === 'admin' || (int) $profile->user_id === (int) $user->id) {
            return null;
        }

        return response()->json(['message' => 'Profile not found.'], 404);
    }

    private function resolveSourceFileDisk(ProfileSource $source, string $path): ?string
    {
        $candidateDisks = [
            data_get($source->metadata, 'file.disk'),
            config('profile-knowledge-ai.sources.disk', 'profiles'),
            'profiles',
            'public',
            'local',
        ];

        foreach (array_unique(array_filter($candidateDisks)) as $diskName) {
            try {
                if (Storage::disk((string) $diskName)->exists($path)) {
                    return (string) $diskName;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function sourceFileName(ProfileSource $source): string
    {
        $fileName = trim((string) ($source->original_filename ?: $source->name));

        return $fileName !== '' ? $fileName : 'profile-source-'.$source->id;
    }
}
