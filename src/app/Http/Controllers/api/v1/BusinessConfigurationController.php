<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Requests\StoreBusinessApiClientRequest;
use App\Http\Requests\UpdateBusinessSettingsRequest;
use App\Models\Business;
use App\Models\BusinessApiClient;
use App\Services\Business\BusinessApiClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessConfigurationController extends BusinessAdminController
{
    public function show(Request $request, Business $business): JsonResponse
    {
        $this->ensureAvailable($request);

        return response()->json(['message' => 'Business configuration retrieved successfully.', 'data' => [
            'settings' => $business->settings()->firstOrCreate(),
            'api_clients' => $business->apiClients()->with('origins')->latest()->get(),
        ]]);
    }

    public function update(UpdateBusinessSettingsRequest $request, Business $business): JsonResponse
    {
        $this->ensureAvailable($request);
        $settings = $business->settings()->firstOrCreate();
        $settings->update($request->validated());

        return response()->json(['message' => 'Business configuration updated successfully.', 'data' => $settings->fresh()]);
    }

    public function storeClient(StoreBusinessApiClientRequest $request, Business $business, BusinessApiClientService $clients): JsonResponse
    {
        $this->ensureAvailable($request);
        $result = $clients->create(
            $business,
            $request->validated('name'),
            $request->validated('origins'),
            $request->validated('expires_at'),
        );

        return response()->json(['message' => 'API key created. It will only be displayed once.', 'data' => [
            'client' => $result['client'], 'key' => $result['key'],
        ]], 201);
    }

    public function revokeClient(Request $request, Business $business, BusinessApiClient $client): JsonResponse
    {
        $this->ensureAvailable($request);
        abort_unless($client->business_id === $business->id, 404);
        $client->update(['enabled' => false]);

        return response()->json(['message' => 'API key revoked successfully.', 'data' => $client->fresh('origins')]);
    }
}
