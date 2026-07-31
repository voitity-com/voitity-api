<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('payment_failure_code', 100)->nullable();
            $table->timestamp('payment_failed_at')->nullable();
            $table->unsignedSmallInteger('payment_retry_count')->default(0);
            $table->timestamp('next_payment_retry_at')->nullable();
            $table->foreignId('last_failed_payment_order_id')
                ->nullable()
                ->constrained('payment_orders')
                ->nullOnDelete();
            $table->string('access_ended_reason', 100)->nullable();

            $table->index(
                ['status', 'next_payment_retry_at'],
                'subscriptions_payment_retry_due_index'
            );
        });

        Schema::table('payment_orders', function (Blueprint $table): void {
            $table->foreignId('source_subscription_id')
                ->nullable()
                ->constrained('subscriptions')
                ->nullOnDelete();
            $table->timestamp('billing_cycle_at')->nullable();
            $table->unsignedSmallInteger('attempt_number')->default(1);

            $table->index(
                ['source_subscription_id', 'billing_cycle_at', 'status'],
                'payment_orders_renewal_cycle_index'
            );
        });

        Schema::table('profiles', function (Blueprint $table): void {
            $table->timestamp('subscription_suspended_at')->nullable();
            $table->foreignId('suspended_by_subscription_id')
                ->nullable()
                ->constrained('subscriptions')
                ->nullOnDelete();
            $table->string('subscription_suspension_previous_status', 50)->nullable();

            $table->index(
                ['user_id', 'subscription_suspended_at'],
                'profiles_subscription_suspension_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropIndex('profiles_subscription_suspension_index');
            $table->dropConstrainedForeignId('suspended_by_subscription_id');
            $table->dropColumn([
                'subscription_suspended_at',
                'subscription_suspension_previous_status',
            ]);
        });

        Schema::table('payment_orders', function (Blueprint $table): void {
            $table->dropIndex('payment_orders_renewal_cycle_index');
            $table->dropConstrainedForeignId('source_subscription_id');
            $table->dropColumn([
                'billing_cycle_at',
                'attempt_number',
            ]);
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex('subscriptions_payment_retry_due_index');
            $table->dropConstrainedForeignId('last_failed_payment_order_id');
            $table->dropColumn([
                'payment_failure_code',
                'payment_failed_at',
                'payment_retry_count',
                'next_payment_retry_at',
                'access_ended_reason',
            ]);
        });
    }
};
