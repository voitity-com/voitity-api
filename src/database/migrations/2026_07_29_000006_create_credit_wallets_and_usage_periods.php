<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_usage_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('plan', 50);
            $table->timestamp('period_started_at');
            $table->timestamp('period_renews_at');
            $table->json('limits_snapshot');
            $table->timestamps();

            $table->unique(['subscription_id', 'period_started_at']);
            $table->index(['user_id', 'period_started_at']);
        });

        Schema::create('credit_wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('available_units')->default(0);
            $table->unsignedBigInteger('reserved_units')->default(0);
            $table->unsignedBigInteger('debt_units')->default(0);
            $table->unsignedBigInteger('lifetime_purchased_units')->default(0);
            $table->unsignedBigInteger('lifetime_consumed_units')->default(0);
            $table->timestamps();
        });

        Schema::table('subscription_limits', function (Blueprint $table): void {
            $table->foreignId('usage_period_id')
                ->nullable()
                ->after('user_id')
                ->constrained('subscription_usage_periods')
                ->nullOnDelete();
        });

        Schema::table('subscription_uses', function (Blueprint $table): void {
            $table->foreignId('usage_period_id')
                ->nullable()
                ->after('user_id')
                ->constrained('subscription_usage_periods')
                ->nullOnDelete();
            $table->json('plan_covered')->nullable()->after('credits_used');
            $table->json('credit_covered')->nullable()->after('plan_covered');
            $table->unsignedBigInteger('purchased_credit_units')->default(0)->after('credit_covered');
            $table->string('credit_tariff_version', 50)->nullable()->after('purchased_credit_units');
            $table->unsignedInteger('reservation_sequence')->default(1)->after('credit_tariff_version');

            $table->index(['usage_period_id', 'status']);
            $table->index(['user_id', 'used_at', 'status']);
        });

        Schema::create('credit_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credit_wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_use_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->bigInteger('amount_units');
            $table->unsignedBigInteger('available_units_after');
            $table->unsignedBigInteger('reserved_units_after');
            $table->unsignedBigInteger('debt_units_after');
            $table->string('idempotency_key', 191)->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
        });

        Schema::table('payment_orders', function (Blueprint $table): void {
            $table->string('product_type', 40)->default('subscription')->after('provider_transaction_id');
            $table->string('product_code', 80)->nullable()->after('product_type');
            $table->unsignedBigInteger('credit_units')->default(0)->after('product_code');
            $table->string('purchase_idempotency_key', 100)->nullable()->unique()->after('credit_units');
            $table->string('plan', 50)->nullable()->change();

            $table->index(['product_type', 'status']);
        });

        $this->backfillCurrentUsagePeriods();
    }

    public function down(): void
    {
        DB::table('payment_orders')->whereNull('plan')->update(['plan' => 'starter']);

        Schema::table('payment_orders', function (Blueprint $table): void {
            $table->dropIndex(['product_type', 'status']);
            $table->dropUnique(['purchase_idempotency_key']);
            $table->dropColumn(['product_type', 'product_code', 'credit_units', 'purchase_idempotency_key']);
            $table->string('plan', 50)->nullable(false)->change();
        });

        Schema::dropIfExists('credit_ledger_entries');

        Schema::table('subscription_uses', function (Blueprint $table): void {
            $table->dropIndex(['usage_period_id', 'status']);
            $table->dropIndex(['user_id', 'used_at', 'status']);
            $table->dropConstrainedForeignId('usage_period_id');
            $table->dropColumn([
                'plan_covered',
                'credit_covered',
                'purchased_credit_units',
                'credit_tariff_version',
                'reservation_sequence',
            ]);
        });

        Schema::table('subscription_limits', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('usage_period_id');
        });

        Schema::dropIfExists('credit_wallets');
        Schema::dropIfExists('subscription_usage_periods');
    }

    private function backfillCurrentUsagePeriods(): void
    {
        DB::table('subscription_limits')
            ->join('subscriptions', 'subscriptions.id', '=', 'subscription_limits.subscription_id')
            ->select([
                'subscription_limits.*',
                'subscriptions.plan',
                'subscriptions.status',
            ])
            ->orderBy('subscription_limits.id')
            ->each(function (object $limit): void {
                $snapshot = $limit->status === 'trialing'
                    ? config('subscriptions.trial.limits', [])
                    : config("subscriptions.plans.{$limit->plan}.limits", []);

                $periodId = DB::table('subscription_usage_periods')->insertGetId([
                    'subscription_id' => $limit->subscription_id,
                    'user_id' => $limit->user_id,
                    'plan' => $limit->plan,
                    'period_started_at' => $limit->period_started_at,
                    'period_renews_at' => $limit->period_renews_at,
                    'limits_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('subscription_limits')
                    ->where('id', $limit->id)
                    ->update([
                        'usage_period_id' => $periodId,
                        'credits_remaining' => 0,
                    ]);

                DB::table('subscription_uses')
                    ->where('subscription_id', $limit->subscription_id)
                    ->where('used_at', '>=', $limit->period_started_at)
                    ->where('used_at', '<', $limit->period_renews_at)
                    ->update(['usage_period_id' => $periodId]);
            });
    }
};
