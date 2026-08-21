<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('business_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('lead_recipient_email')->nullable();
            $table->string('sender_email')->nullable();
            $table->string('sender_name')->nullable();
            $table->string('reply_to_email')->nullable();
            $table->string('locale', 12)->default('es');
            $table->boolean('widget_enabled')->default(false);
            $table->string('widget_title')->default('¿Cómo podemos ayudarte?');
            $table->string('widget_button_label')->default('Hablar con nosotros');
            $table->text('widget_welcome_message')->nullable();
            $table->string('widget_primary_color', 16)->default('#6366F1');
            $table->string('widget_position', 24)->default('bottom-right');
            $table->timestamps();
        });

        Schema::create('business_api_clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->uuid('public_id')->unique();
            $table->string('key_prefix', 24);
            $table->string('key_hash', 64)->unique();
            $table->boolean('enabled')->default(true)->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('business_api_client_origins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_api_client_id')->constrained()->cascadeOnDelete();
            $table->string('origin', 255);
            $table->timestamps();

            $table->unique(['business_api_client_id', 'origin'], 'business_client_origin_unique');
            $table->index('origin');
        });

        Schema::create('business_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('name', 180);
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->string('storage_path')->nullable();
            $table->string('status', 32)->default('processing')->index();
            $table->longText('extracted_text')->nullable();
            $table->unsignedInteger('token_count')->default(0);
            $table->json('metadata')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('business_knowledge_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_source_id')->constrained()->cascadeOnDelete();
            $table->string('chunk_key', 80);
            $table->text('content');
            $table->unsignedInteger('token_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['business_source_id', 'chunk_key'], 'business_source_chunk_unique');
        });

        Schema::create('business_flows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('draft_version_id')->nullable();
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->timestamps();
        });

        Schema::create('business_flow_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_flow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('revision')->default(1);
            $table->string('status', 24)->default('draft')->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['business_flow_id', 'version']);
        });

        Schema::table('business_flows', function (Blueprint $table): void {
            $table->foreign('draft_version_id')->references('id')->on('business_flow_versions')->nullOnDelete();
            $table->foreign('published_version_id')->references('id')->on('business_flow_versions')->nullOnDelete();
        });

        Schema::create('business_flow_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_flow_version_id')->constrained()->cascadeOnDelete();
            $table->string('node_key', 100);
            $table->string('type', 24);
            $table->string('title', 180);
            $table->integer('position_x')->default(0);
            $table->integer('position_y')->default(0);
            $table->json('config')->nullable();
            $table->timestamps();

            $table->unique(['business_flow_version_id', 'node_key'], 'business_flow_node_unique');
        });

        Schema::create('business_flow_edges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_flow_version_id')->constrained()->cascadeOnDelete();
            $table->string('edge_key', 100);
            $table->string('source_node_key', 100);
            $table->string('target_node_key', 100);
            $table->string('source_handle', 80)->nullable();
            $table->string('label', 180)->nullable();
            $table->json('config')->nullable();
            $table->timestamps();

            $table->unique(['business_flow_version_id', 'edge_key'], 'business_flow_edge_unique');
            $table->index(['business_flow_version_id', 'source_node_key'], 'business_flow_edge_source_index');
        });

        Schema::create('business_conversations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_flow_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('business_api_client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('in_progress')->index();
            $table->string('current_node_key', 100)->nullable();
            $table->json('context')->nullable();
            $table->string('origin', 255)->nullable();
            $table->string('visitor_id_hash', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_activity_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('end_reason', 80)->nullable();
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
        });

        Schema::create('business_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('node_key', 100)->nullable();
            $table->string('role', 24);
            $table->text('content');
            $table->json('data')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->timestamps();
        });

        Schema::create('business_node_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_flow_version_id')->constrained()->restrictOnDelete();
            $table->string('node_key', 100);
            $table->string('status', 24);
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('business_leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_conversation_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('created')->index();
            $table->string('full_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone', 80)->nullable();
            $table->string('company')->nullable();
            $table->text('project_summary')->nullable();
            $table->text('ai_solution_summary')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('contacted_at')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('no_response_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
        });

        Schema::create('business_lead_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('business_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_conversation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('business_source_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('business_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 60)->index();
            $table->string('provider', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['business_id', 'occurred_at']);
        });

        Schema::create('business_action_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('node_key', 100);
            $table->string('action_key', 100);
            $table->string('idempotency_key', 180)->unique();
            $table->string('status', 24)->default('pending')->index();
            $table->json('payload')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_action_runs');
        Schema::dropIfExists('business_usage_events');
        Schema::dropIfExists('business_lead_status_histories');
        Schema::dropIfExists('business_leads');
        Schema::dropIfExists('business_node_executions');
        Schema::dropIfExists('business_messages');
        Schema::dropIfExists('business_conversations');
        Schema::dropIfExists('business_flow_edges');
        Schema::dropIfExists('business_flow_nodes');
        Schema::table('business_flows', function (Blueprint $table): void {
            $table->dropForeign(['draft_version_id']);
            $table->dropForeign(['published_version_id']);
        });
        Schema::dropIfExists('business_flow_versions');
        Schema::dropIfExists('business_flows');
        Schema::dropIfExists('business_knowledge_chunks');
        Schema::dropIfExists('business_sources');
        Schema::dropIfExists('business_api_client_origins');
        Schema::dropIfExists('business_api_clients');
        Schema::dropIfExists('business_settings');
        Schema::dropIfExists('businesses');
    }
};
