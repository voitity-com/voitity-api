<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Requests\StoreBusinessSourceRequest;
use App\Models\Business;
use App\Models\BusinessSource;
use App\Services\Business\BusinessSourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BusinessSourceController extends BusinessAdminController
{
    public function index(Request $request, Business $business): JsonResponse
    {
        $this->ensureAvailable($request);

        return response()->json(['message' => 'Business sources retrieved successfully.', 'data' => $business->sources()->latest()->get()]);
    }

    public function store(StoreBusinessSourceRequest $request, Business $business, BusinessSourceService $sources): JsonResponse
    {
        $this->ensureAvailable($request);
        $source = $sources->store(
            $business,
            $request->user(),
            $request->validated('name'),
            $request->file('file'),
            $request->validated('content'),
        );

        return response()->json(['message' => 'Business source indexed successfully.', 'data' => $source], 201);
    }

    public function file(Request $request, Business $business, BusinessSource $source)
    {
        $this->ensureAvailable($request);
        abort_unless($source->business_id === $business->id, 404);
        abort_unless($source->storage_path && Storage::disk('profiles')->exists($source->storage_path), 404);

        return Storage::disk('profiles')->download($source->storage_path, $source->original_filename);
    }

    public function destroy(Request $request, Business $business, BusinessSource $source, BusinessSourceService $sources): JsonResponse
    {
        $this->ensureAvailable($request);
        abort_unless($source->business_id === $business->id, 404);
        $sources->delete($source);

        return response()->json(['message' => 'Business source deleted successfully.']);
    }
}
