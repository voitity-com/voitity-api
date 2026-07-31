<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\Subscriptions\CreditAmount;
use App\Classes\Subscriptions\CreditWalletService;
use App\Enums\CreditLedgerEntryType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Usage\UsageAnalyticsRequest;
use App\Http\Responses\Credits\CreditWalletResponse;
use App\Models\CreditLedgerEntry;
use App\Models\SubscriptionUsagePeriod;
use App\Models\SubscriptionUse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class UsageAnalyticsController extends Controller
{
    private const METRICS = [
        'profiles',
        'avatar_images',
        'avatar_video_seconds',
        'voice_clones',
        'tts_characters',
        'chat_messages',
        'incoming_audio_messages',
        'incoming_audio_seconds',
    ];

    private const CREDIT_SERVICE_BY_METRIC = [
        'avatar_images' => 'avatar_image_created',
        'avatar_video_seconds' => 'avatar_video_created',
        'voice_clones' => 'voice_cloned',
        'tts_characters' => 'voice_tts_characters',
        'chat_messages' => 'chat_message_received',
        'incoming_audio_messages' => 'incoming_audio_message',
        'incoming_audio_seconds' => 'incoming_audio_message',
    ];

    /**
     * @OA\Get(
     *     path="/api/usage",
     *     summary="Get plan and purchased-credit usage analytics",
     *     tags={"Subscriptions"},
     *     security={{"sanctum":{"subscription-limits:read"}}},
     *
     *     @OA\Parameter(name="from", in="query", @OA\Schema(type="string", format="date", example="2026-01-01")),
     *     @OA\Parameter(name="to", in="query", @OA\Schema(type="string", format="date", example="2026-07-29")),
     *     @OA\Parameter(name="group_by", in="query", @OA\Schema(type="string", enum={"day","month"}, default="month")),
     *     @OA\Parameter(name="timezone", in="query", @OA\Schema(type="string", example="America/Bogota")),
     *
     *     @OA\Response(response=200, description="Usage analytics retrieved successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=422, description="Invalid or greater-than-24-month range")
     * )
     */
    public function index(UsageAnalyticsRequest $request, CreditWalletService $wallets): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $timezone = (string) ($request->validated('timezone') ?: config('app.timezone', 'UTC'));
        $localTo = $request->filled('to')
            ? Carbon::createFromFormat('!Y-m-d', (string) $request->validated('to'), $timezone)->endOfDay()
            : now($timezone)->endOfDay();
        $localFrom = $request->filled('from')
            ? Carbon::createFromFormat('!Y-m-d', (string) $request->validated('from'), $timezone)->startOfDay()
            : $localTo->copy()->startOfMonth();

        if ($localFrom->diffInMonths($localTo) > 24) {
            return response()->json([
                'message' => 'Usage analytics range cannot exceed 24 months.',
                'errors' => ['from' => ['Usage analytics range cannot exceed 24 months.']],
            ], 422);
        }

        $from = $localFrom->copy()->utc();
        $to = $localTo->copy()->utc();
        $groupBy = (string) ($request->validated('group_by') ?: 'month');
        $uses = SubscriptionUse::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', SubscriptionUse::STATUS_RELEASED)
            ->whereBetween('used_at', [$from, $to])
            ->orderBy('used_at')
            ->get();
        $periods = SubscriptionUsagePeriod::query()
            ->where('user_id', $user->id)
            ->where('period_started_at', '<=', $to)
            ->where('period_renews_at', '>', $from)
            ->orderBy('period_started_at')
            ->get();
        $ledger = CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->whereBetween('occurred_at', [$from, $to])
            ->orderBy('occurred_at')
            ->get();

        return response()->json([
            'message' => 'Usage analytics retrieved successfully.',
            'data' => [
                'range' => [
                    'from' => $localFrom->toDateString(),
                    'to' => $localTo->toDateString(),
                    'group_by' => $groupBy,
                    'timezone' => $timezone,
                ],
                'wallet' => (new CreditWalletResponse($wallets->walletForUser($user)))->toArray(),
                'summary' => $this->summary($uses, $ledger),
                'periods' => $periods
                    ->map(fn (SubscriptionUsagePeriod $period): array => $this->periodData($period, $uses))
                    ->values()
                    ->all(),
                'series' => $this->series($uses, $ledger, $groupBy, $timezone),
            ],
        ]);
    }

    /**
     * @param  Collection<int, SubscriptionUse>  $uses
     * @param  Collection<int, CreditLedgerEntry>  $ledger
     * @return array<string, mixed>
     */
    private function summary(Collection $uses, Collection $ledger): array
    {
        $planUsed = array_fill_keys(self::METRICS, 0);
        $creditCovered = array_fill_keys(self::METRICS, 0);

        foreach ($uses as $use) {
            foreach ((array) $use->plan_covered as $metric => $amount) {
                if (array_key_exists($metric, $planUsed)) {
                    $planUsed[$metric] += (int) $amount;
                }
            }

            foreach ((array) $use->credit_covered as $metric => $amount) {
                if (array_key_exists($metric, $creditCovered)) {
                    $creditCovered[$metric] += (int) $amount;
                }
            }
        }

        $consumedUnits = (int) $uses
            ->where('status', SubscriptionUse::STATUS_FINALIZED)
            ->sum('purchased_credit_units');
        $reservedUnits = (int) $uses
            ->where('status', SubscriptionUse::STATUS_RESERVED)
            ->sum('purchased_credit_units');
        $purchasedUnits = (int) $ledger
            ->where('type', CreditLedgerEntryType::Purchase)
            ->sum('amount_units');
        $reversedUnits = abs((int) $ledger
            ->where('type', CreditLedgerEntryType::Reversal)
            ->sum('amount_units'));

        return [
            'plan_used' => $planUsed,
            'credit_covered' => $creditCovered,
            'credits' => [
                'purchased' => CreditAmount::unitsToCredits($purchasedUnits),
                'consumed' => CreditAmount::unitsToCredits($consumedUnits),
                'reserved' => CreditAmount::unitsToCredits($reservedUnits),
                'reversed' => CreditAmount::unitsToCredits($reversedUnits),
            ],
        ];
    }

    /**
     * @param  Collection<int, SubscriptionUse>  $uses
     * @return array<string, mixed>
     */
    private function periodData(SubscriptionUsagePeriod $period, Collection $uses): array
    {
        $periodUses = $uses->where('usage_period_id', $period->id);
        $finalizedPeriodUses = $periodUses->where('status', SubscriptionUse::STATUS_FINALIZED);
        $planUsed = array_fill_keys(self::METRICS, 0);

        foreach ($periodUses as $use) {
            foreach ((array) $use->plan_covered as $metric => $amount) {
                if (array_key_exists($metric, $planUsed)) {
                    $planUsed[$metric] += (int) $amount;
                }
            }
        }

        return [
            'id' => $period->id,
            'subscription_id' => $period->subscription_id,
            'plan' => $period->plan->value,
            'period_started_at' => $period->period_started_at->toJSON(),
            'period_renews_at' => $period->period_renews_at->toJSON(),
            'included' => $period->limits_snapshot,
            'plan_used' => $planUsed,
            'purchased_credits_used' => CreditAmount::unitsToCredits(
                (int) $finalizedPeriodUses->sum('purchased_credit_units')
            ),
        ];
    }

    /**
     * @param  Collection<int, SubscriptionUse>  $uses
     * @param  Collection<int, CreditLedgerEntry>  $ledger
     * @return list<array<string, mixed>>
     */
    private function series(
        Collection $uses,
        Collection $ledger,
        string $groupBy,
        string $timezone,
    ): array {
        $usesByBucket = $uses
            ->where('purchased_credit_units', '>', 0)
            ->groupBy(
                fn (SubscriptionUse $use): string => $this->bucket(
                    $use->used_at,
                    $groupBy,
                    $timezone,
                )
            );
        $ledgerByBucket = $ledger
            ->whereIn('type', [
                CreditLedgerEntryType::Purchase,
                CreditLedgerEntryType::Reversal,
            ])
            ->groupBy(
                fn (CreditLedgerEntry $entry): string => $this->bucket(
                    $entry->occurred_at,
                    $groupBy,
                    $timezone,
                )
            );

        return $usesByBucket
            ->keys()
            ->merge($ledgerByBucket->keys())
            ->unique()
            ->sort()
            ->map(function (string $bucket) use ($ledgerByBucket, $usesByBucket): array {
                /** @var Collection<int, SubscriptionUse> $bucketUses */
                $bucketUses = $usesByBucket->get($bucket, collect());
                /** @var Collection<int, CreditLedgerEntry> $bucketLedger */
                $bucketLedger = $ledgerByBucket->get($bucket, collect());
                $finalizedUses = $bucketUses->where('status', SubscriptionUse::STATUS_FINALIZED);
                $reservedUses = $bucketUses->where('status', SubscriptionUse::STATUS_RESERVED);
                $services = $this->creditServices($finalizedUses);

                return [
                    'bucket' => $bucket,
                    'credits' => [
                        'purchased' => CreditAmount::unitsToCredits((int) $bucketLedger
                            ->where('type', CreditLedgerEntryType::Purchase)
                            ->sum('amount_units')),
                        'consumed' => CreditAmount::unitsToCredits(
                            (int) $finalizedUses->sum('purchased_credit_units')
                        ),
                        'reserved' => CreditAmount::unitsToCredits(
                            (int) $reservedUses->sum('purchased_credit_units')
                        ),
                        'reversed' => CreditAmount::unitsToCredits(abs((int) $bucketLedger
                            ->where('type', CreditLedgerEntryType::Reversal)
                            ->sum('amount_units'))),
                    ],
                    'services' => $services,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Split bundled operations, such as avatar image plus video, into the
     * services shown by analytics while preserving the original tariff.
     *
     * @param  Collection<int, SubscriptionUse>  $uses
     * @return array<string, array{operations: int, purchased_credits: float|int}>
     */
    private function creditServices(Collection $uses): array
    {
        $services = [];
        $rates = (array) config('subscriptions.credit_store.rates_in_units', []);

        foreach ($uses as $use) {
            $metadataCosts = (array) (($use->metadata ?? [])['credit_cost_units'] ?? []);

            foreach ((array) $use->credit_covered as $metric => $amount) {
                $service = self::CREDIT_SERVICE_BY_METRIC[$metric] ?? null;

                if (! $service) {
                    continue;
                }

                $units = array_key_exists($metric, $metadataCosts)
                    ? max(0, (int) $metadataCosts[$metric])
                    : max(0, (int) $amount) * max(0, (int) ($rates[$metric] ?? 0));
                $services[$service] ??= ['use_ids' => [], 'units' => 0];
                $services[$service]['use_ids'][(int) $use->id] = true;
                $services[$service]['units'] += $units;
            }
        }

        return collect($services)
            ->map(fn (array $service): array => [
                'operations' => count($service['use_ids']),
                'purchased_credits' => CreditAmount::unitsToCredits((int) $service['units']),
            ])
            ->all();
    }

    private function bucket(Carbon $occurredAt, string $groupBy, string $timezone): string
    {
        $localDate = $occurredAt->copy()->timezone($timezone);

        return $groupBy === 'day'
            ? $localDate->format('Y-m-d')
            : $localDate->format('Y-m');
    }
}
