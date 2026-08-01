<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('app_notifications')
            ->where(function ($query): void {
                $query->where('notification_key', 'new_visitor_message_received')
                    ->orWhere(function ($query): void {
                        $query->where('category', 'billing')
                            ->whereNotIn('notification_key', [
                                'failed_payment',
                                'successful_subscription_renewal',
                                'failed_subscription_renewal',
                                'subscription_renewal_reminder',
                            ]);
                    });
            })
            ->update([
                'read_at' => DB::raw('COALESCE(read_at, CURRENT_TIMESTAMP)'),
                'dismissed_at' => DB::raw('COALESCE(dismissed_at, CURRENT_TIMESTAMP)'),
                'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
            ]);
    }

    public function down(): void
    {
        // Dismissal is intentionally not reversed: reopening historical noise would notify users again.
    }
};
