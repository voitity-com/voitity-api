<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->string('kind', 40)->default('notification')->after('category');
            $table->boolean('visible_in_bell')->default(true)->after('kind');
            $table->index(['user_id', 'kind', 'read_at'], 'app_notifications_user_kind_read_index');
            $table->index(['user_id', 'visible_in_bell', 'read_at', 'dismissed_at'], 'app_notifications_user_bell_read_index');
        });

        $logKeys = [
            'account_email_confirmation',
            'welcome_after_email_verification',
            'profile_created',
            'profile_updated',
            'source_uploaded',
            'source_processing_started',
            'source_approved',
            'source_synchronized',
            'source_data_extracted_ready_to_review',
            'avatar_generation_started',
            'avatar_activated',
            'voice_cloning_started',
            'plan_usage_updated',
            'payment_approved',
            'payment_rejected',
            'plan_activated_or_changed',
            'admin_impersonation_started',
        ];

        DB::table('app_notifications')
            ->whereIn('notification_key', $logKeys)
            ->update([
                'kind' => 'log',
                'visible_in_bell' => false,
            ]);

        DB::table('app_notifications')
            ->whereIn('notification_key', $logKeys)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropIndex('app_notifications_user_kind_read_index');
            $table->dropIndex('app_notifications_user_bell_read_index');
            $table->dropColumn(['kind', 'visible_in_bell']);
        });
    }
};
