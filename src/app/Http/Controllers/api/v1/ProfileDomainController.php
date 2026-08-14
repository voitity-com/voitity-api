<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\ProfileDomainService\ProfileDomainProvider;
use App\Enums\ProfileDomainStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProfileDomainRequest;
use App\Http\Responses\Profile\ProfileDomainResponse;
use App\Jobs\ProfileDomains\DisconnectProfileDomain;
use App\Jobs\ProfileDomains\ProvisionProfileDomain;
use App\Jobs\ProfileDomains\RefreshProfileDomain;
use App\Models\Profile;
use App\Models\ProfileDomain;
use App\Models\User;
use App\Services\Features\FeatureService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProfileDomainController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/profile/{profile}/domain",
     *     tags={"Profile"},
     *     summary="Get the custom domain configuration for a profile",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="profile", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Domain configuration retrieved"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Profile not found")
     * )
     */
    public function show(Request $request, Profile $profile): JsonResponse
    {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $domain = $profile->domain()->first();

        Log::info('Profile domain settings retrieved.', [
            'actor_user_id' => $request->user()?->id,
            'profile_id' => $profile->id,
            'profile_domain_id' => $domain?->id,
            'status' => $domain?->status->value,
        ]);

        return response()->json([
            'message' => 'Profile domain settings retrieved successfully.',
            'data' => ['domain' => $domain ? (new ProfileDomainResponse($domain))->toArray() : null],
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/profile/{profile}/domain",
     *     tags={"Profile"},
     *     summary="Configure a custom domain for a profile",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="profile", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"hostname"}, @OA\Property(property="hostname", type="string", example="perfil.example.com"))),
     *
     *     @OA\Response(response=202, description="Domain provisioning queued"),
     *     @OA\Response(response=409, description="Profile already has another domain"),
     *     @OA\Response(response=422, description="Invalid or already assigned domain")
     * )
     */
    public function store(
        StoreProfileDomainRequest $request,
        Profile $profile,
        ProfileDomainProvider $provider,
        FeatureService $features,
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        if (! $features->isGlobalEnabled(FeatureService::DOMAINS_CUSTOM)) {
            Log::notice('Profile domain configuration rejected because the global feature is disabled.', [
                'actor_user_id' => $request->user()?->id,
                'profile_id' => $profile->id,
            ]);

            return response()->json([
                'message' => 'Custom domains are not available right now.',
            ], 403);
        }

        $hostname = (string) $request->validated('hostname');
        $existing = $profile->domain()->first();

        if ($existing && $existing->hostname !== $hostname) {
            Log::notice('Profile domain replacement rejected until current domain is disconnected.', [
                'actor_user_id' => $request->user()?->id,
                'profile_id' => $profile->id,
                'profile_domain_id' => $existing->id,
            ]);

            return response()->json([
                'message' => 'Disconnect the current domain before configuring another one.',
                'data' => ['domain' => (new ProfileDomainResponse($existing))->toArray()],
            ], 409);
        }

        $assigned = ProfileDomain::query()
            ->where('hostname', $hostname)
            ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))
            ->exists();

        if ($assigned) {
            return response()->json([
                'message' => 'This domain is already assigned to another profile.',
                'errors' => ['hostname' => ['This domain is already assigned to another profile.']],
            ], 422);
        }

        try {
            $domain = $existing ?? ProfileDomain::query()->create([
                'profile_id' => $profile->id,
                'hostname' => $hostname,
                'status' => ProfileDomainStatus::PendingProvisioning->value,
                'provider' => $provider->name(),
                'requested_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }

            Log::notice('Profile domain concurrent assignment rejected.', [
                'actor_user_id' => $request->user()?->id,
                'profile_id' => $profile->id,
                'hostname' => $hostname,
            ]);

            return response()->json([
                'message' => 'This domain is already assigned to another profile.',
                'errors' => ['hostname' => ['This domain is already assigned to another profile.']],
            ], 422);
        }

        $retryStatus = filled($domain->provider_tenant_id)
            ? ProfileDomainStatus::PendingDns
            : ProfileDomainStatus::PendingProvisioning;

        $domain->forceFill([
            'status' => $domain->status === ProfileDomainStatus::Failed
                ? $retryStatus->value
                : $domain->status->value,
            'last_error_code' => null,
            'last_error_message' => null,
            'requested_at' => $domain->requested_at ?? now(),
        ])->save();

        if (filled($domain->provider_tenant_id)) {
            RefreshProfileDomain::dispatch($domain->id);
        } else {
            ProvisionProfileDomain::dispatch($domain->id);
        }

        Log::info('Profile domain configuration queued.', [
            'actor_user_id' => $request->user()?->id,
            'profile_id' => $profile->id,
            'profile_domain_id' => $domain->id,
            'hostname' => $hostname,
            'provider' => $domain->provider,
        ]);

        return response()->json([
            'message' => 'Profile domain configuration queued successfully.',
            'data' => ['domain' => (new ProfileDomainResponse($domain->fresh()))->toArray()],
        ], 202);
    }

    /**
     * @OA\Post(
     *     path="/api/profile/{profile}/domain/verify",
     *     tags={"Profile"}, summary="Verify DNS and certificate status for a profile domain",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="profile", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=202, description="Domain verification queued"),
     *     @OA\Response(response=404, description="Profile or domain not found")
     * )
     */
    public function verify(Request $request, Profile $profile): JsonResponse
    {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $domain = $profile->domain()->first();

        if (! $domain || $domain->status === ProfileDomainStatus::Disconnecting) {
            return response()->json(['message' => 'Profile domain not found.'], 404);
        }

        if ($domain->status === ProfileDomainStatus::Failed && $domain->last_error_code === 'disconnect') {
            return response()->json([
                'message' => 'This domain is waiting for disconnection. Retry disconnecting it instead.',
                'data' => ['domain' => (new ProfileDomainResponse($domain))->toArray()],
            ], 409);
        }

        $retryStatus = filled($domain->provider_tenant_id)
            ? ProfileDomainStatus::PendingDns
            : ProfileDomainStatus::PendingProvisioning;
        $domain->forceFill([
            'status' => $domain->status === ProfileDomainStatus::Failed
                ? $retryStatus->value
                : $domain->status->value,
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();
        RefreshProfileDomain::dispatch($domain->id);

        Log::info('Profile domain manual verification queued.', [
            'actor_user_id' => $request->user()?->id,
            'profile_id' => $profile->id,
            'profile_domain_id' => $domain->id,
            'hostname' => $domain->hostname,
        ]);

        return response()->json([
            'message' => 'Profile domain verification queued successfully.',
            'data' => ['domain' => (new ProfileDomainResponse($domain))->toArray()],
        ], 202);
    }

    /**
     * @OA\Delete(
     *     path="/api/profile/{profile}/domain",
     *     tags={"Profile"}, summary="Disconnect the custom domain from a profile",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="profile", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=202, description="Domain disconnection queued"),
     *     @OA\Response(response=404, description="Profile or domain not found")
     * )
     */
    public function destroy(Request $request, Profile $profile): JsonResponse
    {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $domain = $profile->domain()->first();

        if (! $domain) {
            return response()->json(['message' => 'Profile domain not found.'], 404);
        }

        $domain->forceFill([
            'status' => ProfileDomainStatus::Disconnecting->value,
            'disconnected_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();
        DisconnectProfileDomain::dispatch($domain->id);

        Log::info('Profile domain disconnection queued.', [
            'actor_user_id' => $request->user()?->id,
            'profile_id' => $profile->id,
            'profile_domain_id' => $domain->id,
            'hostname' => $domain->hostname,
        ]);

        return response()->json([
            'message' => 'Profile domain disconnection queued successfully.',
            'data' => ['domain' => (new ProfileDomainResponse($domain))->toArray()],
        ], 202);
    }

    private function authorizeProfile(Request $request, Profile $profile): ?JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->role === 'admin' || (int) $profile->user_id === (int) $user->id) {
            return null;
        }

        Log::notice('Profile domain access rejected.', [
            'actor_user_id' => $user->id,
            'profile_id' => $profile->id,
        ]);

        return response()->json(['message' => 'Profile not found.'], 404);
    }
}
