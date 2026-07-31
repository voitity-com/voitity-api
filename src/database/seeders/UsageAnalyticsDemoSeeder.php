<?php

namespace Database\Seeders;

use App\Classes\Subscriptions\CreditAmount;
use App\Enums\CreditLedgerEntryType;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionUsageType;
use App\Models\CreditLedgerEntry;
use App\Models\CreditWallet;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\SubscriptionUsagePeriod;
use App\Models\SubscriptionUse;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UsageAnalyticsDemoSeeder extends Seeder
{
    private const PREFIX = 'demo:usage-dashboard:';

    private const LIMITS = [
        'profiles' => 1,
        'avatar_images' => 1,
        'avatar_video_seconds' => 5,
        'voice_clones' => 1,
        'tts_characters' => 20000,
        'chat_messages' => 1000,
        'incoming_audio_messages' => 500,
        'incoming_audio_seconds' => 15000,
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('UsageAnalyticsDemoSeeder is restricted to local and testing environments.');
        }

        $user = User::query()
            ->where('email', 'test01@bigmelo.com')
            ->orWhere('name', 'Test01')
            ->firstOrFail();

        DB::transaction(function () use ($user): void {
            $activeSubscription = Subscription::query()
                ->where('user_id', $user->id)
                ->where('active', true)
                ->latest('id')
                ->firstOrFail();
            $historicalSubscription = Subscription::query()
                ->where('user_id', $user->id)
                ->whereKeyNot($activeSubscription->id)
                ->oldest('id')
                ->first() ?? $activeSubscription;
            $limit = SubscriptionLimit::query()
                ->where('subscription_id', $activeSubscription->id)
                ->firstOrFail();
            $wallet = CreditWallet::query()->firstOrCreate(['user_id' => $user->id]);

            $this->removePreviousDemoData($user);
            $baseline = $this->walletBaseline($user);
            $periods = $this->periods($user, $activeSubscription, $historicalSubscription, $limit);
            $events = $this->historicalUses($user, $historicalSubscription, $periods);

            $this->currentPlanUsage($user, $activeSubscription, $limit, $periods->last());
            $this->updateCurrentLimits($limit);
            $this->creditTimeline($user, $wallet, $events, $baseline);
        });

        $this->command?->info('Usage dashboard demo data seeded for Test01.');
    }

    private function removePreviousDemoData(User $user): void
    {
        CreditLedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', 'like', self::PREFIX.'%')
            ->delete();
        SubscriptionUse::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', 'like', self::PREFIX.'%')
            ->delete();
    }

    /**
     * @return array{available: int, consumed: int, debt: int, purchased: int, reserved: int}
     */
    private function walletBaseline(User $user): array
    {
        $ledger = CreditLedgerEntry::query()->where('user_id', $user->id)->get();
        $purchased = (int) $ledger
            ->where('type', CreditLedgerEntryType::Purchase)
            ->sum('amount_units');
        $reversed = abs((int) $ledger
            ->where('type', CreditLedgerEntryType::Reversal)
            ->sum('amount_units'));
        $adjusted = (int) $ledger
            ->where('type', CreditLedgerEntryType::AdminAdjustment)
            ->sum('amount_units');
        $consumed = (int) SubscriptionUse::query()
            ->where('user_id', $user->id)
            ->where('status', SubscriptionUse::STATUS_FINALIZED)
            ->sum('purchased_credit_units');
        $reserved = (int) SubscriptionUse::query()
            ->where('user_id', $user->id)
            ->where('status', SubscriptionUse::STATUS_RESERVED)
            ->sum('purchased_credit_units');
        $net = $purchased + $adjusted - $reversed - $consumed - $reserved;

        return [
            'available' => max(0, $net),
            'consumed' => $consumed,
            'debt' => max(0, -$net),
            'purchased' => $purchased,
            'reserved' => $reserved,
        ];
    }

    /**
     * @return Collection<int, SubscriptionUsagePeriod>
     */
    private function periods(
        User $user,
        Subscription $activeSubscription,
        Subscription $historicalSubscription,
        SubscriptionLimit $limit,
    ) {
        $months = collect(range(11, 1))
            ->map(fn (int $monthsAgo): Carbon => now()->subMonthsNoOverflow($monthsAgo)->startOfMonth());

        $periods = $months->map(function (Carbon $startedAt) use ($historicalSubscription, $user) {
            return SubscriptionUsagePeriod::query()->updateOrCreate(
                [
                    'subscription_id' => $historicalSubscription->id,
                    'period_started_at' => $startedAt,
                ],
                [
                    'user_id' => $user->id,
                    'plan' => SubscriptionPlan::Starter,
                    'period_renews_at' => $startedAt->copy()->addMonth(),
                    'limits_snapshot' => self::LIMITS,
                ],
            );
        });

        $currentPeriod = SubscriptionUsagePeriod::query()->find($limit->usage_period_id);

        if (! $currentPeriod instanceof SubscriptionUsagePeriod) {
            $currentPeriod = SubscriptionUsagePeriod::query()->updateOrCreate(
                [
                    'subscription_id' => $activeSubscription->id,
                    'period_started_at' => $limit->period_started_at,
                ],
                [
                    'user_id' => $user->id,
                    'plan' => $activeSubscription->plan,
                    'period_renews_at' => $limit->period_renews_at,
                    'limits_snapshot' => self::LIMITS,
                ],
            );
            $limit->update(['usage_period_id' => $currentPeriod->id]);
        } else {
            $currentPeriod->update(['limits_snapshot' => self::LIMITS]);
        }

        return $periods->push($currentPeriod);
    }

    /**
     * @param  Collection<int, SubscriptionUsagePeriod>  $periods
     * @return list<array{at: Carbon, kind: string, units: int, use?: SubscriptionUse}>
     */
    private function historicalUses(
        User $user,
        Subscription $subscription,
        $periods,
    ): array {
        $events = [];

        foreach ($periods->take(11)->values() as $index => $period) {
            $month = $period->period_started_at->copy();

            if (in_array($index, [0, 3, 6, 9], true)) {
                $events[] = [
                    'at' => $month->copy()->addDays(2)->setTime(14, 0),
                    'kind' => 'purchase',
                    'units' => CreditAmount::creditsToUnits(1000),
                ];
            }

            if ($index === 8) {
                $events[] = [
                    'at' => $month->copy()->addDays(6)->setTime(10, 30),
                    'kind' => 'reversal',
                    'units' => CreditAmount::creditsToUnits(250),
                ];
            }

            $chatPlan = 560 + (($index * 47) % 390);
            $chatCredit = 60 + (($index * 23) % 190);
            $ttsPlan = 8500 + (($index * 970) % 10500);
            $ttsCredit = 900 + (($index * 410) % 2800);
            $audioMessagesPlan = 260 + (($index * 29) % 210);
            $audioSecondsPlan = min(15000, $audioMessagesPlan * (20 + ($index % 7)));
            $audioSecondsCredit = 420 + (($index * 137) % 1200);

            $definitions = [
                [
                    'type' => SubscriptionUsageType::ChatMessageReceived,
                    'day' => 8,
                    'plan' => ['chat_messages' => $chatPlan],
                    'credit' => ['chat_messages' => $chatCredit],
                    'columns' => ['chat_messages_used' => $chatPlan + $chatCredit],
                ],
                [
                    'type' => SubscriptionUsageType::VoiceTtsCharacters,
                    'day' => 12,
                    'plan' => ['tts_characters' => $ttsPlan],
                    'credit' => ['tts_characters' => $ttsCredit],
                    'columns' => ['tts_characters_used' => $ttsPlan + $ttsCredit],
                ],
                [
                    'type' => SubscriptionUsageType::IncomingAudioMessage,
                    'day' => 16,
                    'plan' => [
                        'incoming_audio_messages' => $audioMessagesPlan,
                        'incoming_audio_seconds' => $audioSecondsPlan,
                    ],
                    'credit' => [
                        'incoming_audio_messages' => 18 + ($index % 12),
                        'incoming_audio_seconds' => $audioSecondsCredit,
                    ],
                    'columns' => [
                        'incoming_audio_messages_used' => $audioMessagesPlan + 18 + ($index % 12),
                        'incoming_audio_seconds_used' => $audioSecondsPlan + $audioSecondsCredit,
                    ],
                ],
                [
                    'type' => SubscriptionUsageType::AvatarImageCreated,
                    'day' => 19,
                    'plan' => ['avatar_images' => 1],
                    'credit' => ['avatar_images' => $index % 2],
                    'columns' => ['avatar_images_used' => 1 + ($index % 2)],
                ],
                [
                    'type' => SubscriptionUsageType::AvatarVideoCreated,
                    'day' => 22,
                    'plan' => ['avatar_video_seconds' => 3 + ($index % 3)],
                    'credit' => ['avatar_video_seconds' => 1 + ($index % 2)],
                    'columns' => ['avatar_video_seconds_used' => 4 + (($index % 3) + ($index % 2))],
                ],
                [
                    'type' => SubscriptionUsageType::VoiceCloned,
                    'day' => 25,
                    'plan' => ['voice_clones' => 1],
                    'credit' => ['voice_clones' => $index % 3 === 0 ? 1 : 0],
                    'columns' => ['voice_clones_used' => $index % 3 === 0 ? 2 : 1],
                ],
            ];

            foreach ($definitions as $serviceIndex => $definition) {
                $usedAt = $month->copy()
                    ->addDays(min($definition['day'], $month->daysInMonth - 1))
                    ->setTime(11 + ($serviceIndex % 5), 15);
                $units = $this->creditUnits($definition['credit']);
                $use = SubscriptionUse::query()->create([
                    'subscription_id' => $subscription->id,
                    'user_id' => $user->id,
                    'usage_period_id' => $period->id,
                    'usage_type' => $definition['type'],
                    'idempotency_key' => self::PREFIX."use:{$index}:{$serviceIndex}",
                    ...$definition['columns'],
                    'credits_used' => CreditAmount::unitsToCredits($units),
                    'plan_covered' => $definition['plan'],
                    'credit_covered' => $definition['credit'],
                    'purchased_credit_units' => $units,
                    'credit_tariff_version' => '2026-07-29-v1',
                    'reservation_sequence' => 1,
                    'status' => SubscriptionUse::STATUS_FINALIZED,
                    'metadata' => ['demo_seed' => 'usage-dashboard-v1'],
                    'used_at' => $usedAt,
                    'reserved_at' => $usedAt,
                    'finalized_at' => $usedAt,
                ]);

                if ($units > 0) {
                    $events[] = [
                        'at' => $usedAt,
                        'kind' => 'consume',
                        'units' => $units,
                        'use' => $use,
                    ];
                }
            }
        }

        return $events;
    }

    private function currentPlanUsage(
        User $user,
        Subscription $subscription,
        SubscriptionLimit $limit,
        SubscriptionUsagePeriod $period,
    ): void {
        $periodStart = $limit->period_started_at->copy()->addMinute();
        $usedAt = now()->subHour()->max($periodStart);
        $definitions = [
            [SubscriptionUsageType::ProfileCreated, ['profiles' => 1], ['profiles_used' => 1]],
            [SubscriptionUsageType::AvatarImageCreated, ['avatar_images' => 1], ['avatar_images_used' => 1]],
            [SubscriptionUsageType::AvatarVideoCreated, ['avatar_video_seconds' => 4], ['avatar_video_seconds_used' => 4]],
            [SubscriptionUsageType::VoiceCloned, ['voice_clones' => 1], ['voice_clones_used' => 1]],
            [SubscriptionUsageType::VoiceTtsCharacters, ['tts_characters' => 17_300], ['tts_characters_used' => 17_300]],
            [SubscriptionUsageType::ChatMessageReceived, ['chat_messages' => 760], ['chat_messages_used' => 760]],
            [
                SubscriptionUsageType::IncomingAudioMessage,
                ['incoming_audio_messages' => 425, 'incoming_audio_seconds' => 12_400],
                ['incoming_audio_messages_used' => 425, 'incoming_audio_seconds_used' => 12_400],
            ],
        ];

        foreach ($definitions as $index => [$type, $covered, $columns]) {
            SubscriptionUse::query()->create([
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'usage_period_id' => $period->id,
                'usage_type' => $type,
                'idempotency_key' => self::PREFIX."current:{$index}",
                ...$columns,
                'credits_used' => 0,
                'plan_covered' => $covered,
                'credit_covered' => [],
                'purchased_credit_units' => 0,
                'reservation_sequence' => 1,
                'status' => SubscriptionUse::STATUS_FINALIZED,
                'metadata' => ['demo_seed' => 'usage-dashboard-v1'],
                'used_at' => $usedAt->copy()->addMinutes($index),
                'reserved_at' => $usedAt->copy()->addMinutes($index),
                'finalized_at' => $usedAt->copy()->addMinutes($index),
            ]);
        }
    }

    private function updateCurrentLimits(SubscriptionLimit $limit): void
    {
        $limit->update([
            'profiles_remaining' => 0,
            'avatar_images_remaining' => 0,
            'avatar_video_seconds_remaining' => 1,
            'voice_clones_remaining' => 0,
            'tts_characters_remaining' => 2700,
            'chat_messages_remaining' => 240,
            'incoming_audio_messages_remaining' => 75,
            'incoming_audio_seconds_remaining' => 2600,
        ]);
    }

    /**
     * @param  list<array{at: Carbon, kind: string, units: int, use?: SubscriptionUse}>  $events
     * @param  array{available: int, consumed: int, debt: int, purchased: int, reserved: int}  $baseline
     */
    private function creditTimeline(User $user, CreditWallet $wallet, array $events, array $baseline): void
    {
        usort($events, fn (array $left, array $right): int => $left['at']->timestamp <=> $right['at']->timestamp);
        $available = $baseline['available'];
        $debt = $baseline['debt'];
        $lifetimeConsumed = $baseline['consumed'];
        $lifetimePurchased = $baseline['purchased'];
        $sequence = 0;

        foreach ($events as $event) {
            $sequence++;

            if ($event['kind'] === 'purchase') {
                $debtPayment = min($debt, $event['units']);
                $debt -= $debtPayment;
                $available += $event['units'] - $debtPayment;
                $lifetimePurchased += $event['units'];
                $this->ledgerEntry(
                    $user,
                    $wallet,
                    CreditLedgerEntryType::Purchase,
                    $event['units'],
                    $available,
                    0,
                    $debt,
                    "purchase:{$sequence}",
                    $event['at'],
                );

                continue;
            }

            if ($event['kind'] === 'reversal') {
                $removed = min($available, $event['units']);
                $available -= $removed;
                $debt += $event['units'] - $removed;
                $this->ledgerEntry(
                    $user,
                    $wallet,
                    CreditLedgerEntryType::Reversal,
                    -$event['units'],
                    $available,
                    0,
                    $debt,
                    "reversal:{$sequence}",
                    $event['at'],
                );

                continue;
            }

            $available = max(0, $available - $event['units']);
            $lifetimeConsumed += $event['units'];
            $use = $event['use'];
            $this->ledgerEntry(
                $user,
                $wallet,
                CreditLedgerEntryType::Reserve,
                -$event['units'],
                $available,
                $event['units'],
                $debt,
                "reserve:{$use->id}",
                $event['at'],
                $use,
            );
            $this->ledgerEntry(
                $user,
                $wallet,
                CreditLedgerEntryType::Consume,
                0,
                $available,
                0,
                $debt,
                "consume:{$use->id}",
                $event['at']->copy()->addSecond(),
                $use,
            );
        }

        $wallet->update([
            'available_units' => $available,
            'reserved_units' => $baseline['reserved'],
            'debt_units' => $debt,
            'lifetime_purchased_units' => $lifetimePurchased,
            'lifetime_consumed_units' => $lifetimeConsumed,
        ]);
    }

    private function ledgerEntry(
        User $user,
        CreditWallet $wallet,
        CreditLedgerEntryType $type,
        int $amountUnits,
        int $availableUnits,
        int $reservedUnits,
        int $debtUnits,
        string $key,
        Carbon $occurredAt,
        ?SubscriptionUse $use = null,
    ): void {
        CreditLedgerEntry::query()->create([
            'credit_wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'subscription_use_id' => $use?->id,
            'type' => $type,
            'amount_units' => $amountUnits,
            'available_units_after' => $availableUnits,
            'reserved_units_after' => $reservedUnits,
            'debt_units_after' => $debtUnits,
            'idempotency_key' => self::PREFIX.$key,
            'metadata' => ['demo_seed' => 'usage-dashboard-v1'],
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * @param  array<string, int>  $covered
     */
    private function creditUnits(array $covered): int
    {
        $rates = config('subscriptions.credit_store.rates_in_units');

        return collect($covered)->reduce(
            fn (int $total, int $amount, string $metric): int => $total
                + ($amount * (int) ($rates[$metric] ?? 0)),
            0,
        );
    }
}
