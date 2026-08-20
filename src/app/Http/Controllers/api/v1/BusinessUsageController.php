<?php

namespace App\Http\Controllers\api\v1;

use App\Enums\BusinessConversationStatus;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessUsageController extends BusinessAdminController
{
    public function show(Request $request, Business $business): JsonResponse
    {
        $this->ensureAvailable($request);
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = $request->date('from')?->startOfDay() ?? now()->subDays(30)->startOfDay();
        $to = $request->date('to')?->endOfDay() ?? now()->endOfDay();
        $events = $business->usageEvents()->whereBetween('occurred_at', [$from, $to]);
        $conversations = $business->conversations()->whereBetween('created_at', [$from, $to]);

        return response()->json(['message' => 'Business usage retrieved successfully.', 'data' => [
            'period' => ['from' => $from->toISOString(), 'to' => $to->toISOString()],
            'tokens' => [
                'input' => (clone $events)->sum('input_tokens'),
                'output' => (clone $events)->sum('output_tokens'),
                'total' => (clone $events)->sum('total_tokens'),
            ],
            'sources' => $business->sources()->whereBetween('created_at', [$from, $to])->count(),
            'messages' => $business->messages()->whereBetween('business_messages.created_at', [$from, $to])->count(),
            'conversations' => (clone $conversations)->count(),
            'leads' => $business->leads()->whereBetween('created_at', [$from, $to])->count(),
            'no_leads' => (clone $conversations)->whereDoesntHave('lead')->where('status', '!=', BusinessConversationStatus::InProgress->value)->count(),
            'events_by_type' => (clone $events)->selectRaw('event_type, COUNT(*) as events, SUM(total_tokens) as tokens')->groupBy('event_type')->get(),
        ]]);
    }
}
