<?php

namespace App\Services\Insights;

use App\Enums\ChatAnalysisStatus;
use App\Enums\ChatStatus;
use App\Enums\ConversationCategory;
use App\Enums\ProfileInsightEventType;
use App\Enums\ProfileProductStatus;
use App\Models\Profile;
use App\Models\ProfileInteractionEvent;
use App\Models\ProfileProduct;
use App\Services\Features\FeatureService;
use App\Services\Products\ProfileProductImageService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ProfileInsightsReportService
{
    public const DEFINITIONS_VERSION = 'v2';

    public function __construct(
        private readonly FeatureService $features,
        private readonly ProfileProductImageService $productImages,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(Profile $profile, ProfileInsightsRange $range): array
    {
        $summary = $this->dashboardSummary($profile, $range);

        return [
            ...$this->base($profile, $range),
            'summary' => $summary,
            'provider_funnel' => $this->providerFunnel($profile, $range),
            'categories' => $this->categories($profile, $range),
            'analysis_coverage' => $this->analysisCoverage($profile, $range),
            'series' => $this->dashboardSeries($profile, $range),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function chats(Profile $profile, ProfileInsightsRange $range): array
    {
        $chatQuery = $this->chatRangeQuery($profile, $range);
        $counts = (clone $chatQuery)->selectRaw(
            'COUNT(*) AS total_chats,
             SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS closed_chats,
             SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS open_chats',
            [ChatStatus::Closed->value, ChatStatus::Open->value]
        )->first();
        $messageCounts = DB::table('messages')
            ->whereIn('chat_id', (clone $chatQuery)->select('id'))
            ->selectRaw(
                'COUNT(*) AS total_messages,
                 SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS visitor_messages,
                 SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS profile_answers',
                ['question', 'answer']
            )->first();
        $perChat = DB::table('messages')
            ->whereIn('chat_id', (clone $chatQuery)->select('id'))
            ->selectRaw('chat_id, COUNT(*) AS message_count')
            ->groupBy('chat_id');
        $messageStats = DB::query()->fromSub($perChat, 'message_counts')
            ->selectRaw('AVG(message_count) AS average_messages, SUM(CASE WHEN message_count = 1 THEN 1 ELSE 0 END) AS single_message_chats')
            ->first();
        $duration = (clone $chatQuery)
            ->whereNotNull('ended_at')
            ->selectRaw($this->averageDurationSql().' AS average_duration_minutes')
            ->first();
        $coverage = $this->analysisCoverage($profile, $range);

        return [
            ...$this->base($profile, $range),
            'summary' => [
                'total_chats' => (int) ($counts->total_chats ?? 0),
                'closed_chats' => (int) ($counts->closed_chats ?? 0),
                'open_chats' => (int) ($counts->open_chats ?? 0),
                'total_messages' => (int) ($messageCounts->total_messages ?? 0),
                'visitor_messages' => (int) ($messageCounts->visitor_messages ?? 0),
                'profile_answers' => (int) ($messageCounts->profile_answers ?? 0),
                'average_messages_per_chat' => round((float) ($messageStats->average_messages ?? 0), 2),
                'average_duration_minutes' => round((float) ($duration->average_duration_minutes ?? 0), 2),
                'single_message_chats' => (int) ($messageStats->single_message_chats ?? 0),
                'average_confidence' => $this->averageConfidence($profile, $range),
            ],
            'goals' => $this->categories($profile, $range),
            'analysis_coverage' => $coverage,
            'goal_trend' => $this->goalTrend($profile, $range),
            'goal_actions' => $this->goalActions($profile, $range),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function products(Profile $profile, ProfileInsightsRange $range): array
    {
        $availability = $this->productAvailability($profile);
        $eventRows = $this->productEventRows($profile, $range);
        $currentProducts = ProfileProduct::query()
            ->where('profile_id', $profile->id)
            ->get()
            ->keyBy(fn (ProfileProduct $product): string => (string) $product->public_id);
        $products = collect($eventRows)
            ->keyBy(fn (object $row): string => (string) $row->product_key)
            ->map(function (object $row, string $key) use ($currentProducts): array {
                /** @var ProfileProduct|null $current */
                $current = $currentProducts->get($key);
                $shown = (int) $row->shown;
                $clicks = (int) $row->clicks;

                return [
                    'key' => $key,
                    'public_id' => $row->subject_public_id ?: ($current?->public_id),
                    'product_id' => $current?->id ?? (is_numeric($row->subject_id) ? (int) $row->subject_id : null),
                    'name' => $current?->name ?? $row->subject_name ?? 'Historical product',
                    'image_url' => $current ? $this->productImages->imageUrl($current) : $row->subject_image_url,
                    'status' => $current?->status->value ?? 'deleted',
                    'historical' => ! $current || $current->status !== ProfileProductStatus::Published,
                    'destination_type' => $current?->destination_type->value ?? $row->destination_type,
                    'shown' => $shown,
                    'clicks' => $clicks,
                    'ctr' => $shown > 0 ? round(($clicks / $shown) * 100, 2) : 0,
                    'unique_click_visitors' => (int) $row->unique_click_visitors,
                    'chats_reached' => (int) $row->chats_reached,
                    'image_clicks' => (int) $row->image_clicks,
                    'button_clicks' => (int) $row->button_clicks,
                    'goals' => [],
                ];
            });

        foreach ($currentProducts->where('status', ProfileProductStatus::Published) as $current) {
            if ($products->has((string) $current->public_id)) {
                continue;
            }

            $products->put((string) $current->public_id, [
                'key' => (string) $current->public_id,
                'public_id' => $current->public_id,
                'product_id' => $current->id,
                'name' => $current->name,
                'image_url' => $this->productImages->imageUrl($current),
                'status' => $current->status->value,
                'historical' => false,
                'destination_type' => $current->destination_type->value,
                'shown' => 0,
                'clicks' => 0,
                'ctr' => 0,
                'unique_click_visitors' => 0,
                'chats_reached' => 0,
                'image_clicks' => 0,
                'button_clicks' => 0,
                'goals' => [],
            ]);
        }

        foreach ($this->productGoals($profile, $range) as $goal) {
            $key = (string) $goal->product_key;

            if ($products->has($key)) {
                $row = $products->get($key);
                $row['goals'][] = [
                    'key' => (string) $goal->primary_category,
                    'chats' => (int) $goal->chats,
                ];
                $products->put($key, $row);
            }
        }

        $rows = $products->sortByDesc(fn (array $product): int => $product['clicks'])->values()->all();
        $shown = array_sum(array_column($rows, 'shown'));
        $clicks = array_sum(array_column($rows, 'clicks'));

        return [
            ...$this->base($profile, $range, $availability),
            'available' => $availability,
            'summary' => [
                'products' => count($rows),
                'shown' => $shown,
                'clicks' => $clicks,
                'ctr' => $shown > 0 ? round(($clicks / $shown) * 100, 2) : 0,
                'unique_click_visitors' => $this->uniqueProductClickVisitors($profile, $range),
            ],
            'products' => $rows,
            'series' => $this->productSeries($profile, $range),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function base(Profile $profile, ProfileInsightsRange $range, ?array $productAvailability = null): array
    {
        return [
            'range' => $range->toArray(),
            'tracking_started_at' => config('insights.tracking_started_at')
                ?: ProfileInteractionEvent::query()->where('profile_id', $profile->id)->min('occurred_at'),
            'definitions_version' => self::DEFINITIONS_VERSION,
            'tabs' => ['products' => $productAvailability ?? $this->productAvailability($profile)],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function dashboardSummary(Profile $profile, ProfileInsightsRange $range): array
    {
        $chats = $this->chatRangeQuery($profile, $range)->count();
        $messages = DB::table('messages')
            ->where('profile_id', $profile->id)
            ->whereBetween('created_at', [$range->from, $range->to])
            ->selectRaw(
                'COUNT(*) AS total_messages,
                 SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS visitor_messages,
                 SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS profile_answers',
                ['question', 'answer']
            )->first();
        $events = $this->eventRangeQuery($profile, $range)
            ->selectRaw($this->dashboardEventSelect())
            ->first();

        return [
            'new_chats' => $chats,
            'total_messages' => (int) ($messages->total_messages ?? 0),
            'visitor_messages' => (int) ($messages->visitor_messages ?? 0),
            'profile_answers' => (int) ($messages->profile_answers ?? 0),
            'unique_visitors' => (int) ($events->unique_visitors ?? 0),
            'product_clicks' => (int) ($events->product_clicks ?? 0),
            'instagram_shown' => (int) ($events->instagram_shown ?? 0),
            'instagram_external_clicks' => (int) ($events->instagram_external_clicks ?? 0),
            'tiktok_shown' => (int) ($events->tiktok_shown ?? 0),
            'tiktok_external_clicks' => (int) ($events->tiktok_external_clicks ?? 0),
            'onlyfans_images_shown' => (int) ($events->onlyfans_images_shown ?? 0),
            'onlyfans_external_clicks' => (int) ($events->onlyfans_external_clicks ?? 0),
        ];
    }

    private function dashboardEventSelect(): string
    {
        return "COUNT(DISTINCT CASE WHEN event_type = 'profile_viewed' THEN visitor_id_hash END) AS unique_visitors,
            SUM(CASE WHEN event_type = 'product_clicked' THEN 1 ELSE 0 END) AS product_clicks,
            SUM(CASE WHEN event_type = 'media_shown' AND provider = 'instagram' THEN 1 ELSE 0 END) AS instagram_shown,
            SUM(CASE WHEN event_type = 'media_external_clicked' AND provider = 'instagram' THEN 1 ELSE 0 END) AS instagram_external_clicks,
            SUM(CASE WHEN event_type = 'media_shown' AND provider = 'tiktok' THEN 1 ELSE 0 END) AS tiktok_shown,
            SUM(CASE WHEN event_type = 'media_external_clicked' AND provider = 'tiktok' THEN 1 ELSE 0 END) AS tiktok_external_clicks,
            SUM(CASE WHEN event_type = 'media_shown' AND provider = 'onlyfans' AND media_type = 'image' THEN 1 ELSE 0 END) AS onlyfans_images_shown,
            SUM(CASE WHEN event_type = 'media_external_clicked' AND provider = 'onlyfans' THEN 1 ELSE 0 END) AS onlyfans_external_clicks";
    }

    /**
     * @return array<int, array<string, int|float|string>>
     */
    private function providerFunnel(Profile $profile, ProfileInsightsRange $range): array
    {
        $rows = $this->eventRangeQuery($profile, $range)
            ->whereIn('provider', ['instagram', 'tiktok', 'onlyfans'])
            ->groupBy('provider')
            ->select('provider')
            ->selectRaw("SUM(CASE WHEN event_type = 'media_shown' THEN 1 ELSE 0 END) AS shown")
            ->selectRaw("SUM(CASE WHEN event_type = 'media_opened' THEN 1 ELSE 0 END) AS opened")
            ->selectRaw("SUM(CASE WHEN event_type = 'media_external_clicked' THEN 1 ELSE 0 END) AS external_clicks")
            ->get()
            ->keyBy('provider');

        return collect(['instagram', 'tiktok', 'onlyfans'])->map(function (string $provider) use ($rows): array {
            $row = $rows->get($provider);
            $shown = (int) ($row->shown ?? 0);
            $clicks = (int) ($row->external_clicks ?? 0);

            return [
                'provider' => $provider,
                'shown' => $shown,
                'opened' => (int) ($row->opened ?? 0),
                'external_clicks' => $clicks,
                'ctr' => $shown > 0 ? round(($clicks / $shown) * 100, 2) : 0,
            ];
        })->all();
    }

    /**
     * @return array<int, array{key: string, count: int, percent: float, average_confidence: float}>
     */
    private function categories(Profile $profile, ProfileInsightsRange $range): array
    {
        $rows = DB::table('chat_analyses')
            ->join('chats', 'chats.id', '=', 'chat_analyses.chat_id')
            ->where('chats.profile_id', $profile->id)
            ->whereBetween('chats.started_at', [$range->from, $range->to])
            ->whereIn('chat_analyses.status', [ChatAnalysisStatus::Completed->value, ChatAnalysisStatus::NeedsReview->value])
            ->groupBy('chat_analyses.primary_category')
            ->select('chat_analyses.primary_category')
            ->selectRaw('COUNT(*) AS aggregate, AVG(chat_analyses.confidence) AS average_confidence')
            ->get()
            ->keyBy('primary_category');
        $chatCount = $this->chatRangeQuery($profile, $range)->count();

        return collect(ConversationCategory::cases())->map(function (ConversationCategory $category) use ($rows, $chatCount): array {
            $row = $rows->get($category->value);
            $count = (int) ($row->aggregate ?? 0);

            return [
                'key' => $category->value,
                'count' => $count,
                'percent' => $chatCount > 0 ? round(($count / $chatCount) * 100, 2) : 0,
                'average_confidence' => round((float) ($row->average_confidence ?? 0), 4),
            ];
        })->all();
    }

    /**
     * @return array<string, int>
     */
    private function analysisCoverage(Profile $profile, ProfileInsightsRange $range): array
    {
        $rows = DB::table('chat_analyses')
            ->join('chats', 'chats.id', '=', 'chat_analyses.chat_id')
            ->where('chats.profile_id', $profile->id)
            ->whereBetween('chats.started_at', [$range->from, $range->to])
            ->groupBy('chat_analyses.status')
            ->select('chat_analyses.status')
            ->selectRaw('COUNT(*) AS aggregate')
            ->pluck('aggregate', 'status');
        $totalChats = $this->chatRangeQuery($profile, $range)->count();
        $completed = (int) ($rows[ChatAnalysisStatus::Completed->value] ?? 0);
        $needsReview = (int) ($rows[ChatAnalysisStatus::NeedsReview->value] ?? 0);

        return [
            'total_chats' => $totalChats,
            'classified' => $completed + $needsReview,
            'completed' => $completed,
            'needs_review' => $needsReview,
            'pending' => (int) ($rows[ChatAnalysisStatus::Pending->value] ?? 0),
            'failed' => (int) ($rows[ChatAnalysisStatus::Failed->value] ?? 0),
            'unclassified' => max(0, $totalChats - (int) $rows->sum()),
        ];
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    private function dashboardSeries(Profile $profile, ProfileInsightsRange $range): array
    {
        $chatRows = $this->groupedCounts($this->chatRangeQuery($profile, $range), 'started_at', $range);
        $messageRows = $this->groupedMessageCounts($profile, $range);
        $eventRows = $this->groupedEventCounts($profile, $range);

        return $this->buckets($range)->map(function (string $bucket) use ($chatRows, $messageRows, $eventRows): array {
            $messages = $messageRows->get($bucket);
            $events = $eventRows->get($bucket);

            return [
                'bucket' => $bucket,
                'new_chats' => (int) ($chatRows->get($bucket)->aggregate ?? 0),
                'total_messages' => (int) ($messages->total_messages ?? 0),
                'visitor_messages' => (int) ($messages->visitor_messages ?? 0),
                'profile_answers' => (int) ($messages->profile_answers ?? 0),
                'unique_visitors' => (int) ($events->unique_visitors ?? 0),
                'product_clicks' => (int) ($events->product_clicks ?? 0),
                'instagram_shown' => (int) ($events->instagram_shown ?? 0),
                'instagram_external_clicks' => (int) ($events->instagram_external_clicks ?? 0),
                'tiktok_shown' => (int) ($events->tiktok_shown ?? 0),
                'tiktok_external_clicks' => (int) ($events->tiktok_external_clicks ?? 0),
                'onlyfans_images_shown' => (int) ($events->onlyfans_images_shown ?? 0),
                'onlyfans_external_clicks' => (int) ($events->onlyfans_external_clicks ?? 0),
            ];
        })->all();
    }

    private function groupedMessageCounts(Profile $profile, ProfileInsightsRange $range)
    {
        [$bucketSql, $bindings] = $this->bucketSql('created_at', $range);

        return DB::table('messages')
            ->where('profile_id', $profile->id)
            ->whereBetween('created_at', [$range->from, $range->to])
            ->groupByRaw($bucketSql, $bindings)
            ->selectRaw("{$bucketSql} AS bucket", $bindings)
            ->selectRaw("COUNT(*) AS total_messages,
                SUM(CASE WHEN type = 'question' THEN 1 ELSE 0 END) AS visitor_messages,
                SUM(CASE WHEN type = 'answer' THEN 1 ELSE 0 END) AS profile_answers")
            ->get()
            ->keyBy('bucket');
    }

    private function groupedEventCounts(Profile $profile, ProfileInsightsRange $range)
    {
        [$bucketSql, $bindings] = $this->bucketSql('occurred_at', $range);

        return $this->eventRangeQuery($profile, $range)
            ->groupByRaw($bucketSql, $bindings)
            ->selectRaw("{$bucketSql} AS bucket", $bindings)
            ->selectRaw($this->dashboardEventSelect())
            ->get()
            ->keyBy('bucket');
    }

    private function groupedCounts(Builder $query, string $column, ProfileInsightsRange $range)
    {
        [$bucketSql, $bindings] = $this->bucketSql($column, $range);

        return $query
            ->groupByRaw($bucketSql, $bindings)
            ->selectRaw("{$bucketSql} AS bucket, COUNT(*) AS aggregate", $bindings)
            ->get()
            ->keyBy('bucket');
    }

    /**
     * @return array<int, array{bucket: string, goals: array<int, array{key: string, count: int}>}>
     */
    private function goalTrend(Profile $profile, ProfileInsightsRange $range): array
    {
        [$bucketSql, $bindings] = $this->bucketSql('chats.started_at', $range);
        $rows = DB::table('chat_analyses')
            ->join('chats', 'chats.id', '=', 'chat_analyses.chat_id')
            ->where('chats.profile_id', $profile->id)
            ->whereBetween('chats.started_at', [$range->from, $range->to])
            ->whereNotNull('chat_analyses.primary_category')
            ->groupByRaw("{$bucketSql}, chat_analyses.primary_category", $bindings)
            ->selectRaw("{$bucketSql} AS bucket", $bindings)
            ->addSelect('chat_analyses.primary_category')
            ->selectRaw('COUNT(*) AS aggregate')
            ->get()
            ->groupBy('bucket');

        return $this->buckets($range)->map(fn (string $bucket): array => [
            'bucket' => $bucket,
            'goals' => $rows->get($bucket, collect())->map(fn (object $row): array => [
                'key' => (string) $row->primary_category,
                'count' => (int) $row->aggregate,
            ])->values()->all(),
        ])->all();
    }

    /**
     * @return array<int, array<string, int|float|string>>
     */
    private function goalActions(Profile $profile, ProfileInsightsRange $range): array
    {
        $rows = DB::table('chat_analyses')
            ->join('chats', 'chats.id', '=', 'chat_analyses.chat_id')
            ->leftJoin('profile_interaction_events AS events', function ($join) use ($range): void {
                $join->on('events.chat_id', '=', 'chats.id')
                    ->whereBetween('events.occurred_at', [$range->from, $range->to]);
            })
            ->where('chats.profile_id', $profile->id)
            ->whereBetween('chats.started_at', [$range->from, $range->to])
            ->whereNotNull('chat_analyses.primary_category')
            ->groupBy('chat_analyses.primary_category')
            ->select('chat_analyses.primary_category')
            ->selectRaw('COUNT(DISTINCT chats.id) AS chats')
            ->selectRaw("COUNT(DISTINCT CASE WHEN events.event_type = 'product_clicked' THEN chats.id END) AS product_click_chats")
            ->selectRaw("COUNT(DISTINCT CASE WHEN events.event_type = 'product_clicked' AND events.destination_type = 'whatsapp' THEN chats.id END) AS whatsapp_click_chats")
            ->selectRaw("COUNT(DISTINCT CASE WHEN events.event_type = 'social_link_clicked' THEN chats.id END) AS social_click_chats")
            ->selectRaw("COUNT(DISTINCT CASE WHEN events.event_type = 'media_external_clicked' THEN chats.id END) AS media_exit_chats")
            ->get()
            ->keyBy('primary_category');

        return collect(ConversationCategory::cases())->map(function (ConversationCategory $category) use ($rows): array {
            $row = $rows->get($category->value);
            $chats = (int) ($row->chats ?? 0);
            $productChats = (int) ($row->product_click_chats ?? 0);

            return [
                'key' => $category->value,
                'chats' => $chats,
                'product_click_chats' => $productChats,
                'product_click_rate' => $chats > 0 ? round(($productChats / $chats) * 100, 2) : 0,
                'whatsapp_click_chats' => (int) ($row->whatsapp_click_chats ?? 0),
                'social_click_chats' => (int) ($row->social_click_chats ?? 0),
                'media_exit_chats' => (int) ($row->media_exit_chats ?? 0),
            ];
        })->all();
    }

    private function averageConfidence(Profile $profile, ProfileInsightsRange $range): float
    {
        return round((float) DB::table('chat_analyses')
            ->join('chats', 'chats.id', '=', 'chat_analyses.chat_id')
            ->where('chats.profile_id', $profile->id)
            ->whereBetween('chats.started_at', [$range->from, $range->to])
            ->whereIn('chat_analyses.status', [ChatAnalysisStatus::Completed->value, ChatAnalysisStatus::NeedsReview->value])
            ->avg('chat_analyses.confidence'), 4);
    }

    private function productEventRows(Profile $profile, ProfileInsightsRange $range)
    {
        $keySql = $this->productKeySql();

        return $this->productEventQuery($profile, $range)
            ->groupByRaw($keySql)
            ->selectRaw("{$keySql} AS product_key")
            ->selectRaw('MAX(COALESCE(events.subject_public_id, '.$this->productPublicIdSql().')) AS subject_public_id')
            ->selectRaw('MAX(events.subject_id) AS subject_id, MAX(COALESCE(events.subject_name, products.name)) AS subject_name')
            ->selectRaw('MAX(COALESCE(events.subject_image_url, products.image_url)) AS subject_image_url')
            ->selectRaw('MAX(COALESCE(events.destination_type, products.destination_type)) AS destination_type')
            ->selectRaw("SUM(CASE WHEN events.event_type = 'product_shown' THEN 1 ELSE 0 END) AS shown")
            ->selectRaw("SUM(CASE WHEN events.event_type = 'product_clicked' THEN 1 ELSE 0 END) AS clicks")
            ->selectRaw("COUNT(DISTINCT CASE WHEN events.event_type = 'product_clicked' THEN events.visitor_id_hash END) AS unique_click_visitors")
            ->selectRaw('COUNT(DISTINCT events.chat_id) AS chats_reached')
            ->selectRaw("SUM(CASE WHEN events.event_type = 'product_clicked' AND events.surface = 'product_image' THEN 1 ELSE 0 END) AS image_clicks")
            ->selectRaw("SUM(CASE WHEN events.event_type = 'product_clicked' AND events.surface = 'product_button' THEN 1 ELSE 0 END) AS button_clicks")
            ->get();
    }

    private function productGoals(Profile $profile, ProfileInsightsRange $range)
    {
        $keySql = $this->productKeySql();

        return DB::table('profile_interaction_events AS events')
            ->leftJoin('profile_products AS products', function ($join): void {
                $join->on('products.profile_id', '=', 'events.profile_id')
                    ->whereRaw($this->productIdJoinSql());
            })
            ->join('chat_analyses', 'chat_analyses.chat_id', '=', 'events.chat_id')
            ->where('events.profile_id', $profile->id)
            ->whereBetween('events.occurred_at', [$range->from, $range->to])
            ->where('events.subject_type', 'product')
            ->where('events.event_type', ProfileInsightEventType::ProductClicked->value)
            ->whereNotNull('chat_analyses.primary_category')
            ->groupByRaw("{$keySql}, chat_analyses.primary_category")
            ->selectRaw("{$keySql} AS product_key")
            ->addSelect('chat_analyses.primary_category')
            ->selectRaw('COUNT(DISTINCT events.chat_id) AS chats')
            ->get();
    }

    /**
     * @return array<int, array{bucket: string, shown: int, clicks: int}>
     */
    private function productSeries(Profile $profile, ProfileInsightsRange $range): array
    {
        [$bucketSql, $bindings] = $this->bucketSql('occurred_at', $range);
        $rows = $this->eventRangeQuery($profile, $range)
            ->where('subject_type', 'product')
            ->whereIn('event_type', [ProfileInsightEventType::ProductShown->value, ProfileInsightEventType::ProductClicked->value])
            ->groupByRaw($bucketSql, $bindings)
            ->selectRaw("{$bucketSql} AS bucket", $bindings)
            ->selectRaw("SUM(CASE WHEN event_type = 'product_shown' THEN 1 ELSE 0 END) AS shown")
            ->selectRaw("SUM(CASE WHEN event_type = 'product_clicked' THEN 1 ELSE 0 END) AS clicks")
            ->get()
            ->keyBy('bucket');

        return $this->buckets($range)->map(fn (string $bucket): array => [
            'bucket' => $bucket,
            'shown' => (int) ($rows->get($bucket)->shown ?? 0),
            'clicks' => (int) ($rows->get($bucket)->clicks ?? 0),
        ])->all();
    }

    /**
     * @return array{available: bool, active_products: int, historical_products: int, mode: string}
     */
    private function productAvailability(Profile $profile): array
    {
        $featureEnabled = $this->features->isProfileFeatureEnabled($profile, FeatureService::PRODUCTS);
        $activeProducts = $profile->products()->where('status', ProfileProductStatus::Published->value)->count();
        $historicalProducts = (int) $this->productEventQuery($profile)
            ->distinct()
            ->count(DB::raw($this->productKeySql()));
        $available = $featureEnabled && ($activeProducts > 0 || $historicalProducts > 0);

        return [
            'available' => $available,
            'active_products' => $activeProducts,
            'historical_products' => $historicalProducts,
            'mode' => ! $available
                ? 'none'
                : ($activeProducts > 0 ? ($historicalProducts > 0 ? 'active_and_history' : 'active') : 'historical_only'),
        ];
    }

    private function chatRangeQuery(Profile $profile, ProfileInsightsRange $range): Builder
    {
        return DB::table('chats')
            ->where('profile_id', $profile->id)
            ->whereBetween('started_at', [$range->from, $range->to]);
    }

    private function eventRangeQuery(Profile $profile, ProfileInsightsRange $range): Builder
    {
        return DB::table('profile_interaction_events')
            ->where('profile_id', $profile->id)
            ->whereBetween('occurred_at', [$range->from, $range->to]);
    }

    private function productEventQuery(Profile $profile, ?ProfileInsightsRange $range = null): Builder
    {
        return DB::table('profile_interaction_events AS events')
            ->leftJoin('profile_products AS products', function ($join): void {
                $join->on('products.profile_id', '=', 'events.profile_id')
                    ->whereRaw($this->productIdJoinSql());
            })
            ->where('events.profile_id', $profile->id)
            ->when($range, fn (Builder $query): Builder => $query->whereBetween('events.occurred_at', [$range->from, $range->to]))
            ->where('events.subject_type', 'product')
            ->whereIn('events.event_type', [ProfileInsightEventType::ProductShown->value, ProfileInsightEventType::ProductClicked->value]);
    }

    private function uniqueProductClickVisitors(Profile $profile, ProfileInsightsRange $range): int
    {
        return (int) $this->eventRangeQuery($profile, $range)
            ->where('subject_type', 'product')
            ->where('event_type', ProfileInsightEventType::ProductClicked->value)
            ->whereNotNull('visitor_id_hash')
            ->distinct()
            ->count('visitor_id_hash');
    }

    private function productKeySql(): string
    {
        return 'COALESCE(events.subject_public_id, '.$this->productPublicIdSql().', events.subject_id)';
    }

    private function productPublicIdSql(): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql' => 'CAST(products.public_id AS CHAR)',
            default => 'CAST(products.public_id AS TEXT)',
        };
    }

    private function productIdJoinSql(): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql' => 'CAST(products.id AS CHAR) = events.subject_id',
            default => 'CAST(products.id AS TEXT) = events.subject_id',
        };
    }

    private function averageDurationSql(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => 'AVG(EXTRACT(EPOCH FROM (ended_at - started_at)) / 60)',
            'mysql' => 'AVG(TIMESTAMPDIFF(SECOND, started_at, ended_at) / 60)',
            default => 'AVG((julianday(ended_at) - julianday(started_at)) * 1440)',
        };
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    private function bucketSql(string $column, ProfileInsightsRange $range): array
    {
        $format = $range->groupBy === 'month' ? 'YYYY-MM' : 'YYYY-MM-DD';

        return match (DB::connection()->getDriverName()) {
            'pgsql' => [
                'TO_CHAR(timezone('.DB::connection()->getPdo()->quote($range->timezone).", {$column}), '{$format}')",
                [],
            ],
            'mysql' => [
                'DATE_FORMAT('.$column.", '".($range->groupBy === 'month' ? '%Y-%m' : '%Y-%m-%d')."')",
                [],
            ],
            default => [
                "strftime('".($range->groupBy === 'month' ? '%Y-%m' : '%Y-%m-%d')."', {$column})",
                [],
            ],
        };
    }

    private function buckets(ProfileInsightsRange $range)
    {
        $cursor = $range->localFrom->copy()->startOf($range->groupBy);
        $end = $range->localTo->copy()->startOf($range->groupBy);
        $format = $range->groupBy === 'month' ? 'Y-m' : 'Y-m-d';
        $buckets = collect();

        while ($cursor->lte($end)) {
            $buckets->push($cursor->format($format));
            $cursor->add(1, $range->groupBy);
        }

        return $buckets;
    }
}
