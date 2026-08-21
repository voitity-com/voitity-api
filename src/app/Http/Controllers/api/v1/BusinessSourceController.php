<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Requests\StoreBusinessSourceRequest;
use App\Models\Business;
use App\Models\BusinessSource;
use App\Services\Business\BusinessSourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        return response()->json(['message' => 'Business source created and queued for indexing successfully.', 'data' => $source], 201);
    }

    public function file(Request $request, Business $business, BusinessSource $source): StreamedResponse
    {
        $this->ensureAvailable($request);
        abort_unless($source->business_id === $business->id, 404);

        if ($source->storage_path && Storage::disk('profiles')->exists($source->storage_path)) {
            return Storage::disk('profiles')->download($source->storage_path, $source->downloadFilename());
        }

        abort_unless($source->type === 'text' && filled($source->extracted_text), 404);

        return response()->streamDownload(
            static function () use ($source): void {
                echo (string) $source->extracted_text;
            },
            $source->downloadFilename(),
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    public function destroy(Request $request, Business $business, BusinessSource $source, BusinessSourceService $sources): JsonResponse
    {
        $this->ensureAvailable($request);
        abort_unless($source->business_id === $business->id, 404);
        $sources->delete($source);

        return response()->json(['message' => 'Business source deleted successfully.']);
    }
}
