<?php

namespace App\Services\Insights;

use App\Http\Requests\Insights\ProfileInsightsRequest;
use Illuminate\Support\Carbon;

final readonly class ProfileInsightsRange
{
    public function __construct(
        public Carbon $localFrom,
        public Carbon $localTo,
        public Carbon $from,
        public Carbon $to,
        public string $timezone,
        public string $groupBy,
    ) {}

    public static function fromRequest(ProfileInsightsRequest $request): self
    {
        $timezone = (string) ($request->validated('timezone') ?: config('app.timezone', 'UTC'));
        $localTo = $request->filled('to')
            ? Carbon::createFromFormat('!Y-m-d', (string) $request->validated('to'), $timezone)->endOfDay()
            : now($timezone)->endOfDay();
        $localFrom = $request->filled('from')
            ? Carbon::createFromFormat('!Y-m-d', (string) $request->validated('from'), $timezone)->startOfDay()
            : $localTo->copy()->subMonthNoOverflow()->startOfDay();
        $groupBy = (string) ($request->validated('group_by') ?: ($localFrom->diffInDays($localTo) > 92 ? 'month' : 'day'));

        return new self(
            localFrom: $localFrom,
            localTo: $localTo,
            from: $localFrom->copy()->utc(),
            to: $localTo->copy()->utc(),
            timezone: $timezone,
            groupBy: $groupBy,
        );
    }

    public function exceedsMaximum(): bool
    {
        return $this->localFrom->diffInMonths($this->localTo) > (int) config('insights.max_range_months', 24);
    }

    /**
     * @return array{from: string, to: string, timezone: string, group_by: string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->localFrom->toDateString(),
            'to' => $this->localTo->toDateString(),
            'timezone' => $this->timezone,
            'group_by' => $this->groupBy,
        ];
    }
}
