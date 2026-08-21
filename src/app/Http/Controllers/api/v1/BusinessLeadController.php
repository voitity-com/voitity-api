<?php

namespace App\Http\Controllers\api\v1;

use App\Enums\BusinessLeadStatus;
use App\Http\Requests\ListBusinessLeadsRequest;
use App\Http\Requests\UpdateBusinessLeadStatusRequest;
use App\Models\Business;
use App\Models\BusinessLead;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessLeadController extends BusinessAdminController
{
    public function index(ListBusinessLeadsRequest $request, Business $business): JsonResponse
    {
        $this->ensureAvailable($request);
        $validated = $request->validated();
        $dateField = $validated['date_field'] ?? 'created_at';
        $timezone = $validated['timezone'] ?? 'UTC';
        $fromDate = $validated['from'] ?? now($timezone)->subMonth()->toDateString();
        $toDate = $validated['to'] ?? now($timezone)->toDateString();
        $from = CarbonImmutable::createFromFormat('Y-m-d', $fromDate, $timezone)->startOfDay()->utc();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $toDate, $timezone)->endOfDay()->utc();
        $query = $business->leads()
            ->with([
                'conversation:id,uuid,started_at,completed_at',
                'histories' => fn ($historyQuery) => $historyQuery
                    ->with('changedBy:id,name,email')
                    ->oldest('created_at')
                    ->oldest('id'),
            ])
            ->whereBetween($dateField, [$from, $to])
            ->latest($dateField)
            ->latest('id');
        if (($validated['statuses'] ?? []) !== []) {
            $query->whereIn('status', $validated['statuses']);
        }
        $leads = $query->paginate(25);

        return response()->json(['message' => 'Business leads retrieved successfully.', 'data' => $leads->items(), 'meta' => [
            'current_page' => $leads->currentPage(), 'last_page' => $leads->lastPage(), 'per_page' => $leads->perPage(), 'total' => $leads->total(),
        ]]);
    }

    public function update(UpdateBusinessLeadStatusRequest $request, Business $business, BusinessLead $lead): JsonResponse
    {
        $this->ensureAvailable($request);
        abort_unless($lead->business_id === $business->id, 404);
        $validated = $request->validated();
        $previous = $lead->status->value;
        $status = BusinessLeadStatus::from($validated['status']);
        if ($status === $lead->status) {
            throw ValidationException::withMessages(['status' => 'Selecciona un estado diferente al actual.']);
        }
        $timestamps = match ($status) {
            BusinessLeadStatus::Contacted => ['contacted_at' => now()],
            BusinessLeadStatus::Sale => ['sold_at' => now()],
            BusinessLeadStatus::NoResponse => ['no_response_at' => now()],
            BusinessLeadStatus::Closed => ['closed_at' => now()],
            default => [],
        };
        DB::transaction(function () use ($lead, $previous, $request, $status, $timestamps, $validated): void {
            $lead->update(['status' => $status, ...$timestamps]);
            $lead->histories()->create([
                'changed_by_user_id' => $request->user()?->id,
                'from_status' => $previous,
                'to_status' => $status->value,
                'note' => filled($validated['note'] ?? null) ? trim($validated['note']) : null,
            ]);
        });

        return response()->json(['message' => 'Lead status updated successfully.', 'data' => $lead->fresh([
            'conversation:id,uuid,started_at,completed_at',
            'histories' => fn ($historyQuery) => $historyQuery->with('changedBy:id,name,email')->oldest('created_at')->oldest('id'),
        ])]);
    }
}
