<?php

namespace App\Http\Controllers\api\v1;

use App\Enums\BusinessLeadStatus;
use App\Models\Business;
use App\Models\BusinessLead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusinessLeadController extends BusinessAdminController
{
    public function index(Request $request, Business $business): JsonResponse
    {
        $this->ensureAvailable($request);
        $query = $business->leads()->with('conversation:id,uuid,started_at,completed_at')->latest();
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        $leads = $query->paginate(25);

        return response()->json(['message' => 'Business leads retrieved successfully.', 'data' => $leads->items(), 'meta' => [
            'current_page' => $leads->currentPage(), 'last_page' => $leads->lastPage(), 'total' => $leads->total(),
        ]]);
    }

    public function update(Request $request, Business $business, BusinessLead $lead): JsonResponse
    {
        $this->ensureAvailable($request);
        abort_unless($lead->business_id === $business->id, 404);
        $validated = $request->validate([
            'status' => ['required', Rule::enum(BusinessLeadStatus::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $previous = $lead->status->value;
        $status = BusinessLeadStatus::from($validated['status']);
        $timestamps = match ($status) {
            BusinessLeadStatus::Contacted => ['contacted_at' => now()],
            BusinessLeadStatus::Sale => ['sold_at' => now()],
            BusinessLeadStatus::NoResponse => ['no_response_at' => now()],
            default => [],
        };
        $lead->update(['status' => $status, ...$timestamps]);
        $lead->histories()->create([
            'changed_by_user_id' => $request->user()?->id,
            'from_status' => $previous,
            'to_status' => $status->value,
            'note' => $validated['note'] ?? null,
        ]);

        return response()->json(['message' => 'Lead status updated successfully.', 'data' => $lead->fresh('histories')]);
    }
}
