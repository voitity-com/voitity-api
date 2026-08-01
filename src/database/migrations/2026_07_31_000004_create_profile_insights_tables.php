<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            $table->string('status')->default('open')->after('profile_id');
            $table->timestampTz('started_at')->nullable()->after('status');
            $table->timestampTz('last_activity_at')->nullable()->after('started_at');
            $table->timestampTz('ended_at')->nullable()->after('last_activity_at');
            $table->string('ended_reason')->nullable()->after('ended_at');
            $table->string('visitor_id_hash', 64)->nullable()->after('ended_reason');
            $table->index(['profile_id', 'started_at']);
            $table->index(['status', 'last_activity_at']);
            $table->index(['profile_id', 'visitor_id_hash', 'started_at'], 'chats_profile_visitor_started_index');
        });

        DB::table('chats')->update([
            'started_at' => DB::raw('created_at'),
            'last_activity_at' => DB::raw('(SELECT MAX(messages.created_at) FROM messages WHERE messages.chat_id = chats.id)'),
        ]);
        DB::table('chats')->whereNull('last_activity_at')->update(['last_activity_at' => DB::raw('created_at')]);

        Schema::create('profile_interaction_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_id')->nullable()->constrained()->nullOnDelete();
            $table->string('visitor_id_hash', 64)->nullable();
            $table->string('event_type', 64);
            $table->string('subject_type', 32)->nullable();
            $table->string('subject_id', 128)->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('surface', 64)->nullable();
            $table->string('media_type', 32)->nullable();
            $table->timestampTz('occurred_at');
            $table->json('metadata')->nullable();
            $table->string('idempotency_key', 128)->unique();
            $table->timestampsTz();
            $table->index(['profile_id', 'event_type', 'occurred_at'], 'profile_events_type_time_index');
            $table->index(['profile_id', 'provider', 'occurred_at'], 'profile_events_provider_time_index');
            $table->index(['profile_id', 'visitor_id_hash', 'occurred_at'], 'profile_events_visitor_time_index');
        });

        Schema::create('chat_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chat_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('pending');
            $table->string('primary_category', 64)->nullable();
            $table->json('secondary_categories')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->text('summary')->nullable();
            $table->json('evidence_message_ids')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version', 32)->default('v1');
            $table->string('taxonomy_version', 32)->default('v1');
            $table->timestampTz('analyzed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();
            $table->index(['profile_id', 'primary_category', 'analyzed_at'], 'chat_analyses_category_time_index');
            $table->index(['profile_id', 'status']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('chat_analyses');
        Schema::dropIfExists('profile_interaction_events');

        Schema::table('chats', function (Blueprint $table): void {
            $table->dropIndex(['profile_id', 'started_at']);
            $table->dropIndex(['status', 'last_activity_at']);
            $table->dropIndex('chats_profile_visitor_started_index');
            $table->dropColumn([
                'status', 'started_at', 'last_activity_at', 'ended_at', 'ended_reason', 'visitor_id_hash',
            ]);
        });
    }
};
