<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_interaction_events', function (Blueprint $table): void {
            $table->string('subject_public_id', 128)->nullable()->after('subject_id');
            $table->string('subject_name', 255)->nullable()->after('subject_public_id');
            $table->string('subject_status', 40)->nullable()->after('subject_name');
            $table->string('subject_image_url', 2048)->nullable()->after('subject_status');
            $table->string('destination_type', 40)->nullable()->after('subject_image_url');

            $table->index(
                ['profile_id', 'subject_type', 'event_type', 'occurred_at'],
                'profile_events_subject_type_time_index'
            );
            $table->index(
                ['profile_id', 'subject_public_id', 'occurred_at'],
                'profile_events_subject_public_time_index'
            );
            $table->index(
                ['profile_id', 'chat_id', 'event_type', 'occurred_at'],
                'profile_events_chat_type_time_index'
            );
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->index(['profile_id', 'created_at', 'type'], 'messages_profile_time_type_index');
            $table->index(['chat_id', 'created_at'], 'messages_chat_time_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_profile_time_type_index');
            $table->dropIndex('messages_chat_time_index');
        });

        Schema::table('profile_interaction_events', function (Blueprint $table): void {
            $table->dropIndex('profile_events_subject_type_time_index');
            $table->dropIndex('profile_events_subject_public_time_index');
            $table->dropIndex('profile_events_chat_type_time_index');
            $table->dropColumn([
                'subject_public_id',
                'subject_name',
                'subject_status',
                'subject_image_url',
                'destination_type',
            ]);
        });
    }
};
