<?php

namespace App\Http\Controllers\api\v1;

use App\Enums\BusinessStatus;
use App\Http\Requests\StoreBusinessRequest;
use App\Http\Requests\UpdateBusinessRequest;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use App\Services\Business\BusinessFlowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessController extends BusinessAdminController
{
    /**
     * @OA\Get(path="/api/businesses", tags={"Business Admin"}, summary="List businesses", security={{"sanctum":{}}},
     *
     *   @OA\Response(response=200, description="Businesses ordered by update"), @OA\Response(response=403, description="Admin required"), @OA\Response(response=404, description="Feature disabled"))
     */
    public function index(Request $request): JsonResponse
    {
        $this->ensureAvailable($request);
        $businesses = Business::query()->withCount([
            'sources',
            'leads',
            'leads as unread_leads_count' => fn (Builder $query) => $query->whereNull('read_at'),
        ])->latest('updated_at')->paginate(20);

        return response()->json([
            'message' => 'Businesses retrieved successfully.',
            'data' => BusinessResource::collection($businesses->getCollection()),
            'meta' => ['current_page' => $businesses->currentPage(), 'last_page' => $businesses->lastPage(), 'total' => $businesses->total()],
        ]);
    }

    public function store(StoreBusinessRequest $request, BusinessFlowService $flows): JsonResponse
    {
        $this->ensureAvailable($request);
        $business = DB::transaction(function () use ($request, $flows): Business {
            $business = Business::query()->create([
                ...$request->validated(),
                'created_by_user_id' => $request->user()?->id,
                'status' => BusinessStatus::Draft,
            ]);
            $business->settings()->create([]);
            $flows->initialize($business, $request->user());

            return $business;
        });

        return response()->json(['message' => 'Business created successfully.', 'data' => new BusinessResource($this->loadCounts($business))], 201);
    }

    public function show(Request $request, Business $business): JsonResponse
    {
        $this->ensureAvailable($request);

        return response()->json(['message' => 'Business retrieved successfully.', 'data' => new BusinessResource($this->loadCounts($business))]);
    }

    public function update(UpdateBusinessRequest $request, Business $business): JsonResponse
    {
        $this->ensureAvailable($request);
        $business->update($request->validated());

        return response()->json(['message' => 'Business updated successfully.', 'data' => new BusinessResource($this->loadCounts($business->fresh()))]);
    }

    public function activate(Request $request, Business $business): JsonResponse
    {
        $this->ensureAvailable($request);
        if (! $business->flow?->published_version_id) {
            throw ValidationException::withMessages(['flow' => 'Publica una versión válida del flow antes de activar el negocio.']);
        }
        $settings = $business->settings()->firstOrCreate();
        if (! $settings->lead_recipient_email) {
            throw ValidationException::withMessages([
                'configuration' => 'Configura el email receptor de leads antes de activar el negocio.',
            ]);
        }
        $business->update(['status' => BusinessStatus::Active, 'activated_at' => now()]);

        return response()->json(['message' => 'Business activated successfully.', 'data' => new BusinessResource($this->loadCounts($business->fresh()))]);
    }

    public function deactivate(Request $request, Business $business): JsonResponse
    {
        $this->ensureAvailable($request);
        $business->update(['status' => BusinessStatus::Paused]);

        return response()->json(['message' => 'Business paused successfully.', 'data' => new BusinessResource($this->loadCounts($business->fresh()))]);
    }

    public function destroy(Request $request, Business $business): JsonResponse
    {
        $this->ensureAvailable($request);
        $business->delete();

        return response()->json(['message' => 'Business deleted successfully.']);
    }

    private function loadCounts(Business $business): Business
    {
        return $business->loadCount([
            'sources',
            'leads',
            'leads as unread_leads_count' => fn (Builder $query) => $query->whereNull('read_at'),
        ]);
    }
}
