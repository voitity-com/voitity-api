<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50)->default('wompi');
            $table->string('provider_source_id', 150)->nullable();
            $table->string('type', 50)->nullable();
            $table->string('status', 50)->default('pending');
            $table->boolean('reusable')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_source_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('payment_source_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->unsignedBigInteger('source_payment_order_id')->nullable()->after('payment_source_id');
            $table->string('billing_mode', 50)->default('recurring')->after('plan');
            $table->boolean('cancel_at_period_end')->default(false)->after('active');
            $table->timestamp('cancelled_at')->nullable()->after('cancel_at_period_end');
            $table->timestamp('last_billed_at')->nullable()->after('cancelled_at');
            $table->timestamp('next_billing_at')->nullable()->after('last_billed_at');

            $table->index('source_payment_order_id', 'subscriptions_source_payment_order_id_index');
            $table->index(['billing_mode', 'next_billing_at'], 'subscriptions_billing_mode_next_billing_at_index');
        });

        Schema::table('payment_orders', function (Blueprint $table) {
            $table->foreignId('payment_source_id')->nullable()->after('subscription_id')->constrained()->nullOnDelete();
            $table->boolean('recurring')->default(true)->after('plan');
            $table->string('billing_reason', 50)->default('subscription_initial')->after('recurring');
        });
    }

    public function down(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_source_id');
            $table->dropColumn(['recurring', 'billing_reason']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_source_id');
            $table->dropIndex('subscriptions_source_payment_order_id_index');
            $table->dropIndex('subscriptions_billing_mode_next_billing_at_index');
            $table->dropColumn([
                'source_payment_order_id',
                'billing_mode',
                'cancel_at_period_end',
                'cancelled_at',
                'last_billed_at',
                'next_billing_at',
            ]);
        });

        Schema::dropIfExists('payment_sources');
    }
};
