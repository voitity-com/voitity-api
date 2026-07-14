<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('free_trial_used_at')->nullable()->after('google_verified_at');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('trial_started_at')->nullable()->after('started_at');
            $table->timestamp('trial_ends_at')->nullable()->after('trial_started_at');
            $table->timestamp('trial_converted_at')->nullable()->after('trial_ends_at');
            $table->timestamp('trial_cancelled_at')->nullable()->after('trial_converted_at');

            $table->index(['active', 'trial_ends_at'], 'subscriptions_active_trial_ends_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex('subscriptions_active_trial_ends_at_index');
            $table->dropColumn([
                'trial_started_at',
                'trial_ends_at',
                'trial_converted_at',
                'trial_cancelled_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('free_trial_used_at');
        });
    }
};
