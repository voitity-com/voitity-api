<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_sources', function (Blueprint $table): void {
            $table->boolean('requires_attention')->default(false)->after('is_default');
            $table->string('last_payment_failure_code', 100)->nullable()->after('requires_attention');
            $table->timestamp('last_payment_failed_at')->nullable()->after('last_payment_failure_code');
            $table->unsignedBigInteger('last_failed_payment_order_id')
                ->nullable()
                ->after('last_payment_failed_at');

            $table->index(
                ['user_id', 'requires_attention', 'disabled_at'],
                'payment_sources_user_attention_enabled_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('payment_sources', function (Blueprint $table): void {
            $table->dropIndex('payment_sources_user_attention_enabled_index');
            $table->dropColumn([
                'requires_attention',
                'last_payment_failure_code',
                'last_payment_failed_at',
                'last_failed_payment_order_id',
            ]);
        });
    }
};
