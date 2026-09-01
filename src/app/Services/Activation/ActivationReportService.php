<?php

namespace App\Services\Activation;

use App\Enums\ActivationEventType;
use App\Models\ActivationEvent;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ActivationReportService
{
    /** @param array<string, mixed> $filters */
    public function summary(Carbon $from, Carbon $to, array $filters): array
    {
        $trialEvents = $this->trialCohort($from, $to, $filters)->get();
        $userIds = $trialEvents->pluck('user_id')->unique()->values();
        $events = $this->eventsForCohort($userIds, $to);
        $usersByType = $events
            ->groupBy(fn (ActivationEvent $event): string => $event->event_type->value)
            ->map(fn (Collection $rows): Collection => $rows->pluck('user_id')->unique()->values());

        $previous = null;
        $funnel = collect(ActivationEventType::funnel())->map(function (ActivationEventType $type) use (&$previous, $usersByType, $userIds): array {
            $count = $type === ActivationEventType::TrialStarted
                ? $userIds->count()
                : ($usersByType->get($type->value, collect()))->count();
            $previousCount = $previous ?? $userIds->count();
            $row = [
                'event' => $type->value,
                'users' => $count,
                'conversion_previous' => $previousCount > 0 ? round(($count / $previousCount) * 100, 1) : 0,
                'conversion_total' => $userIds->count() > 0 ? round(($count / $userIds->count()) * 100, 1) : 0,
                'drop_off' => max(0, $previousCount - $count),
            ];
            $previous = $count;

            return $row;
        })->values()->all();

        $activatedUserIds = $this->activatedUserIds($usersByType, $userIds);
        $subscriptions = Subscription::query()->whereIn('user_id', $userIds)->get();
        $converted = $subscriptions->whereNotNull('trial_converted_at')->pluck('user_id')->unique()->count();
        $cancelled = $subscriptions->whereNotNull('trial_cancelled_at')->pluck('user_id')->unique()->count();
        $paymentFailed = $subscriptions->whereNotNull('payment_failed_at')->pluck('user_id')->unique()->count();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'overview' => [
                'trials_started' => $userIds->count(),
                'users_activated' => $activatedUserIds->count(),
                'converted_to_paid' => $converted,
                'trial_cancelled' => $cancelled,
                'payment_failed' => $paymentFailed,
                'activation_rate' => $userIds->count() > 0 ? round(($activatedUserIds->count() / $userIds->count()) * 100, 1) : 0,
                'paid_conversion_rate' => $userIds->count() > 0 ? round(($converted / $userIds->count()) * 100, 1) : 0,
                'average_hours_to_publish' => $this->averageHoursToPublish($events),
            ],
            'funnel' => $funnel,
            'campaigns' => $this->campaignRows($trialEvents, $usersByType, $subscriptions),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function users(Carbon $from, Carbon $to, array $filters, int $perPage): LengthAwarePaginator
    {
        $trialEvents = $this->trialCohort($from, $to, $filters)->get()->keyBy('user_id');
        $userIds = $trialEvents->keys();
        $query = User::query()
            ->whereIn('id', $userIds)
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function (Builder $query) use ($filters): void {
                $term = '%'.mb_strtolower(trim((string) $filters['search'])).'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query->whereRaw('LOWER(name) LIKE ?', [$term])->orWhereRaw('LOWER(email) LIKE ?', [$term]);
                });
            })
            ->with(['activeSubscription', 'profiles' => fn ($query) => $query->latest('id')])
            ->orderByDesc('id');

        $page = $query->paginate($perPage);
        $pageUserIds = collect($page->items())->pluck('id');
        $events = $this->eventsForCohort($pageUserIds, $to)->groupBy('user_id');

        $page->setCollection(collect($page->items())->map(function (User $user) use ($events, $trialEvents): array {
            $userEvents = $events->get($user->id, collect());
            $completed = $userEvents->pluck('event_type')->map(fn (ActivationEventType $type): string => $type->value)->unique();
            $nextStep = collect(ActivationEventType::funnel())
                ->first(fn (ActivationEventType $type): bool => ! $completed->contains($type->value));
            $profile = $user->profiles->first();
            $subscription = $user->activeSubscription;
            $trialEvent = $trialEvents->get($user->id);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'plan' => $subscription?->plan?->value,
                'subscription_status' => $subscription?->status?->value,
                'trial_started_at' => $trialEvent?->occurred_at?->toJSON(),
                'trial_ends_at' => $subscription?->trial_ends_at?->toJSON(),
                'trial_days_remaining' => $subscription?->trial_ends_at
                    ? max(0, (int) now()->startOfDay()->diffInDays($subscription->trial_ends_at, false))
                    : null,
                'last_event' => $userEvents->sortByDesc('occurred_at')->first()?->event_type?->value,
                'next_step' => $nextStep?->value,
                'activated' => collect(ActivationEventType::commercialActivation())
                    ->every(fn (ActivationEventType $type): bool => $completed->contains($type->value)),
                'completed_events' => $completed->values()->all(),
                'profile' => $profile ? [
                    'id' => $profile->id,
                    'name' => $profile->name,
                    'alias' => $profile->alias,
                    'published' => $profile->active,
                ] : null,
                'attribution' => [
                    'utm_source' => $trialEvent?->utm_source,
                    'utm_medium' => $trialEvent?->utm_medium,
                    'utm_campaign' => $trialEvent?->utm_campaign,
                    'utm_content' => $trialEvent?->utm_content,
                ],
            ];
        }));

        return $page;
    }

    /** @param array<string, mixed> $filters */
    private function trialCohort(Carbon $from, Carbon $to, array $filters): Builder
    {
        return ActivationEvent::query()
            ->where('event_type', ActivationEventType::TrialStarted->value)
            ->whereBetween('occurred_at', [$from, $to])
            ->when(filled($filters['campaign'] ?? null), fn (Builder $query) => $query->where('utm_campaign', $filters['campaign']))
            ->when(filled($filters['source'] ?? null), fn (Builder $query) => $query->where('utm_source', $filters['source']))
            ->when(filled($filters['medium'] ?? null), fn (Builder $query) => $query->where('utm_medium', $filters['medium']))
            ->oldest('occurred_at');
    }

    /** @param Collection<int, int|string> $userIds */
    private function eventsForCohort(Collection $userIds, Carbon $to): Collection
    {
        if ($userIds->isEmpty()) {
            return collect();
        }

        return ActivationEvent::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('event_type', collect(ActivationEventType::funnel())->map->value->all())
            ->where('occurred_at', '<=', $to)
            ->orderBy('occurred_at')
            ->get();
    }

    private function activatedUserIds(Collection $usersByType, Collection $cohort): Collection
    {
        return collect(ActivationEventType::commercialActivation())->reduce(
            function (Collection $users, ActivationEventType $type) use ($usersByType): Collection {
                return $users->intersect($usersByType->get($type->value, collect()));
            },
            $cohort,
        )->values();
    }

    private function averageHoursToPublish(Collection $events): ?float
    {
        $hours = $events->groupBy('user_id')->map(function (Collection $userEvents): ?float {
            $trial = $userEvents->firstWhere('event_type', ActivationEventType::TrialStarted);
            $published = $userEvents->firstWhere('event_type', ActivationEventType::ProfilePublished);

            if (! $trial instanceof ActivationEvent || ! $published instanceof ActivationEvent) {
                return null;
            }

            return round($trial->occurred_at->diffInMinutes($published->occurred_at) / 60, 1);
        })->filter(fn (?float $value): bool => $value !== null);

        return $hours->isEmpty() ? null : round((float) $hours->avg(), 1);
    }

    private function campaignRows(Collection $trialEvents, Collection $usersByType, Collection $subscriptions): array
    {
        return $trialEvents
            ->groupBy(fn (ActivationEvent $event): string => implode('|', [
                $event->utm_campaign ?: '(none)',
                $event->utm_source ?: '(direct)',
                $event->utm_medium ?: '(none)',
            ]))
            ->map(function (Collection $events) use ($subscriptions, $usersByType): array {
                $userIds = $events->pluck('user_id')->unique();
                $published = $userIds->intersect($usersByType->get(ActivationEventType::ProfilePublished->value, collect()))->count();
                $activated = $this->activatedUserIds($usersByType, $userIds)->count();
                $paid = $subscriptions->whereIn('user_id', $userIds)->whereNotNull('trial_converted_at')->pluck('user_id')->unique()->count();
                $first = $events->first();

                return [
                    'campaign' => $first->utm_campaign,
                    'source' => $first->utm_source,
                    'medium' => $first->utm_medium,
                    'trials_started' => $userIds->count(),
                    'profiles_published' => $published,
                    'users_activated' => $activated,
                    'converted_to_paid' => $paid,
                ];
            })
            ->sortByDesc('trials_started')
            ->values()
            ->all();
    }
}
