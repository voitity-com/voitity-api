<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Requests\SaveBusinessFlowRequest;
use App\Models\Business;
use App\Services\Business\BusinessFlowService;
use App\Services\Business\BusinessFlowValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessFlowController extends BusinessAdminController
{
    public function show(Request $request, Business $business, BusinessFlowService $flows): JsonResponse
    {
        $this->ensureAvailable($request);
        $flow = $business->flow()->with(['draftVersion.nodes', 'draftVersion.edges', 'publishedVersion'])->firstOrFail();

        return response()->json(['message' => 'Business flow retrieved successfully.', 'data' => [
            'id' => $flow->id,
            'draft_version' => $flow->draftVersion ? [
                'id' => $flow->draftVersion->id,
                'version' => $flow->draftVersion->version,
                'revision' => $flow->draftVersion->revision,
                ...$flows->serializeVersion($flow->draftVersion),
            ] : null,
            'published_version' => $flow->publishedVersion ? [
                'id' => $flow->publishedVersion->id,
                'version' => $flow->publishedVersion->version,
                'published_at' => $flow->publishedVersion->published_at?->toISOString(),
            ] : null,
        ]]);
    }

    public function update(SaveBusinessFlowRequest $request, Business $business, BusinessFlowService $flows): JsonResponse
    {
        $this->ensureAvailable($request);
        $version = $flows->saveDraft($business->flow()->firstOrFail(), $request->validated(), $request->user());

        return response()->json(['message' => 'Business flow saved successfully.', 'data' => [
            'id' => $version->id, 'version' => $version->version, 'revision' => $version->revision,
            ...$flows->serializeVersion($version),
        ]]);
    }

    public function validateFlow(SaveBusinessFlowRequest $request, Business $business, BusinessFlowValidator $validator): JsonResponse
    {
        $this->ensureAvailable($request);

        return response()->json(['message' => 'Business flow validated.', 'data' => $validator->validate($request->validated('nodes'), $request->validated('edges'))]);
    }

    public function publish(Request $request, Business $business, BusinessFlowService $flows): JsonResponse
    {
        $this->ensureAvailable($request);
        $version = $flows->publish($business->flow()->firstOrFail(), $request->user());

        return response()->json(['message' => 'Business flow published successfully.', 'data' => [
            'id' => $version->id, 'version' => $version->version, 'published_at' => $version->published_at?->toISOString(),
        ]]);
    }
}
