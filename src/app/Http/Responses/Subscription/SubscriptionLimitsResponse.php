<?php

namespace App\Http\Responses\Subscription;

use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SubscriptionLimitsResponse
{
    private const METRICS = [
        'credits' => [
            'remaining' => 'credits_remaining',
            'used' => 'credits_used',
            'decimal' => true,
        ],
        'profiles' => [
            'remaining' => 'profiles_remaining',
            'used' => 'profiles_used',
        ],
        'avatar_images' => [
            'remaining' => 'avatar_images_remaining',
            'used' => 'avatar_images_used',
        ],
        'avatar_video_seconds' => [
            'remaining' => 'avatar_video_seconds_remaining',
            'used' => 'avatar_video_seconds_used',
        ],
        'voice_clones' => [
            'remaining' => 'voice_clones_remaining',
            'used' => 'voice_clones_used',
        ],
        'tts_characters' => [
            'remaining' => 'tts_characters_remaining',
            'used' => 'tts_characters_used',
        ],
        'chat_messages' => [
            'remaining' => 'chat_messages_remaining',
            'used' => 'chat_messages_used',
        ],
    ];

    /**
     * @param  array<string, int|float>  $usageTotals
     * @param  Collection<int, object>  $usageBreakdown
     */
    public function __construct(
        private readonly Subscription $subscription,
        private readonly array $usageTotals,
        private readonly Collection $usageBreakdown
    ) {}

    public function toArray(): array
    {
        return [
            'subscription' => $this->subscriptionData(),
            'limits' => $this->limitsData(),
            'usage' => [
                'totals' => $this->usageData($this->usageTotals),
                'by_type' => $this->usageBreakdownData(),
            ],
        ];
    }

    private function subscriptionData(): array
    {
        $plan = $this->enumValue($this->subscription->plan);
        $planConfig = config("subscriptions.plans.{$plan}", []);

        return [
            'id' => $this->subscription->id,
            'user_id' => $this->subscription->user_id,
            'plan' => $plan,
            'plan_name' => $planConfig['name'] ?? null,
            'unlimited' => $this->isUnlimitedPlan(),
            'price_usd' => $planConfig['price_usd'] ?? null,
            'currency' => $planConfig['currency'] ?? null,
            'interval' => $planConfig['interval'] ?? null,
            'status' => $this->enumValue($this->subscription->status),
            'active' => (bool) $this->subscription->active,
            'billing_mode' => $this->subscription->billing_mode,
            'cancel_at_period_end' => (bool) $this->subscription->cancel_at_period_end,
            'started_at' => $this->subscription->started_at?->toJSON(),
            'renews_at' => $this->subscription->renews_at?->toJSON(),
            'next_billing_at' => $this->subscription->next_billing_at?->toJSON(),
        ];
    }

    private function limitsData(): array
    {
        $limit = $this->subscription->limit;
        $unlimited = $this->isUnlimitedPlan();
        $plan = $this->enumValue($this->subscription->plan);

        return collect(self::METRICS)
            ->mapWithKeys(function (array $columns, string $metric) use ($limit, $plan, $unlimited): array {
                $remaining = $this->usageValue($metric, $limit?->{$columns['remaining']} ?? 0);
                $used = $this->usageValue($metric, $this->usageTotals[$metric] ?? 0);

                return [
                    $metric => [
                        'included' => $unlimited ? null : $this->includedValue($metric, $plan),
                        'remaining' => $unlimited ? null : $remaining,
                        'unlimited' => $unlimited,
                        'used' => $used,
                    ],
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, int|float>  $usage
     */
    private function usageData(array $usage): array
    {
        return collect(self::METRICS)
            ->mapWithKeys(fn (array $columns, string $metric): array => [
                $metric => $this->usageValue($metric, $usage[$metric] ?? 0),
            ])
            ->all();
    }

    private function usageBreakdownData(): array
    {
        return $this->usageBreakdown
            ->map(fn (object $row): array => [
                'usage_type' => $this->enumValue($row->usage_type),
                'records_count' => (int) $row->records_count,
                'used' => $this->usageData([
                    'credits' => (float) $row->credits_used,
                    'profiles' => (int) $row->profiles_used,
                    'avatar_images' => (int) $row->avatar_images_used,
                    'avatar_video_seconds' => (int) $row->avatar_video_seconds_used,
                    'voice_clones' => (int) $row->voice_clones_used,
                    'tts_characters' => (int) $row->tts_characters_used,
                    'chat_messages' => (int) $row->chat_messages_used,
                ]),
                'last_used_at' => $this->dateTimeToJson($row->last_used_at ?? null),
            ])
            ->values()
            ->all();
    }

    private function isUnlimitedPlan(): bool
    {
        $plan = $this->enumValue($this->subscription->plan);

        return (bool) config("subscriptions.plans.{$plan}.unlimited", false);
    }

    private function usageValue(string $metric, mixed $value): int|float
    {
        if ((bool) (self::METRICS[$metric]['decimal'] ?? false)) {
            return round((float) $value, 2);
        }

        return (int) $value;
    }

    private function includedValue(string $metric, ?string $plan): int|float
    {
        if ($metric === 'credits') {
            return $this->usageValue($metric, config("subscriptions.plans.{$plan}.credits.total", 0));
        }

        return $this->usageValue($metric, config("subscriptions.plans.{$plan}.limits.{$metric}", 0));
    }

    private function dateTimeToJson(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toJSON();
        }

        return Carbon::parse((string) $value)->toJSON();
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value === null) {
            return null;
        }

        return (string) $value;
    }
}
