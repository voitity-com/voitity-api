<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Activation\ActivationReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminActivationReportController extends Controller
{
    public function summary(Request $request, ActivationReportService $reports): JsonResponse
    {
        if (! $request->user() instanceof User || $request->user()->role !== 'admin') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        [$from, $to, $filters] = $this->filters($request);

        return response()->json([
            'message' => 'Activation report retrieved successfully.',
            'data' => $reports->summary($from, $to, $filters),
        ]);
    }

    public function users(Request $request, ActivationReportService $reports): JsonResponse
    {
        if (! $request->user() instanceof User || $request->user()->role !== 'admin') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        [$from, $to, $filters] = $this->filters($request, true);
        $page = $reports->users($from, $to, $filters, (int) ($filters['per_page'] ?? 20));

        return response()->json([
            'message' => 'Activation report users retrieved successfully.',
            'data' => [
                'users' => $page->items(),
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ],
        ]);
    }

    /** @return array{Carbon, Carbon, array<string, mixed>} */
    private function filters(Request $request, bool $withPagination = false): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'campaign' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'medium' => ['nullable', 'string', 'max:255'],
            ...($withPagination ? [
                'search' => ['nullable', 'string', 'max:255'],
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ] : []),
        ]);
        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : now()->subDays(29)->startOfDay();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : now()->endOfDay();

        abort_if($from->diffInDays($to) > 366, 422, 'The report period may not exceed 366 days.');

        return [$from, $to, $validated];
    }
}
