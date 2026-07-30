<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_limits', function (Blueprint $table): void {
            $table->unsignedInteger('incoming_audio_messages_remaining')->default(0);
            $table->unsignedInteger('incoming_audio_seconds_remaining')->default(0);
        });

        Schema::table('subscription_uses', function (Blueprint $table): void {
            $table->unsignedInteger('incoming_audio_messages_used')->default(0);
            $table->unsignedInteger('incoming_audio_seconds_used')->default(0);
            $table->string('status', 20)->default('finalized')->index();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('released_at')->nullable();
        });

        DB::table('subscription_limits')
            ->orderBy('id')
            ->chunkById(100, function ($limits): void {
                foreach ($limits as $limit) {
                    $subscription = DB::table('subscriptions')
                        ->where('id', $limit->subscription_id)
                        ->first(['plan', 'status']);

                    if (! $subscription) {
                        continue;
                    }

                    [$messages, $seconds] = match (true) {
                        $subscription->plan === 'admin' => [2147483647, 2147483647],
                        $subscription->status === 'trialing' => [25, 750],
                        default => [500, 15000],
                    };

                    DB::table('subscription_limits')
                        ->where('id', $limit->id)
                        ->update([
                            'incoming_audio_messages_remaining' => $messages,
                            'incoming_audio_seconds_remaining' => $seconds,
                        ]);
                }
            });

        DB::table('subscription_uses')->update([
            'status' => 'finalized',
            'reserved_at' => DB::raw('used_at'),
            'finalized_at' => DB::raw('used_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('subscription_uses', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn([
                'incoming_audio_messages_used',
                'incoming_audio_seconds_used',
                'status',
                'reserved_at',
                'finalized_at',
                'released_at',
            ]);
        });

        Schema::table('subscription_limits', function (Blueprint $table): void {
            $table->dropColumn([
                'incoming_audio_messages_remaining',
                'incoming_audio_seconds_remaining',
            ]);
        });
    }
};
