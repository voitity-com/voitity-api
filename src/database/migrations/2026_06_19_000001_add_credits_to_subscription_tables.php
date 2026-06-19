<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_limits', function (Blueprint $table) {
            $table->decimal('credits_remaining', 10, 2)->default(0)->after('chat_messages_remaining');
        });

        Schema::table('subscription_uses', function (Blueprint $table) {
            $table->decimal('credits_used', 10, 2)->default(0)->after('chat_messages_used');
        });

        DB::table('subscription_limits')
            ->join('subscriptions', 'subscription_limits.subscription_id', '=', 'subscriptions.id')
            ->select([
                'subscription_limits.id',
                'subscriptions.plan',
                'subscription_limits.tts_characters_remaining',
                'subscription_limits.chat_messages_remaining',
            ])
            ->orderBy('subscription_limits.id')
            ->each(function (object $limit): void {
                if ($limit->plan !== 'starter') {
                    return;
                }

                $creditsRemaining = min(
                    1000,
                    round((((int) $limit->chat_messages_remaining) * 0.5) + (((int) $limit->tts_characters_remaining) * 0.05), 2)
                );

                DB::table('subscription_limits')
                    ->where('id', $limit->id)
                    ->update(['credits_remaining' => $creditsRemaining]);
            });
    }

    public function down(): void
    {
        Schema::table('subscription_uses', function (Blueprint $table) {
            $table->dropColumn('credits_used');
        });

        Schema::table('subscription_limits', function (Blueprint $table) {
            $table->dropColumn('credits_remaining');
        });
    }
};
