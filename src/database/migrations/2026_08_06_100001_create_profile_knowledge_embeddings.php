<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        Schema::create('profile_knowledge_indexes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->string('index_version', 60)->nullable();
            $table->string('embedding_model')->nullable();
            $table->unsignedInteger('embedding_dimensions')->nullable();
            $table->unsignedInteger('total_chunks')->default(0);
            $table->unsignedInteger('active_chunks')->default(0);
            $table->unsignedBigInteger('embedding_tokens')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('profile_knowledge_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->string('chunk_key', 190);
            $table->string('source_type', 60)->index();
            $table->string('source_id', 100)->nullable();
            $table->string('locale', 10)->nullable()->index();
            $table->text('content');
            $table->char('content_hash', 64)->index();
            $table->json('metadata')->nullable();
            $table->string('visibility', 30)->default('public')->index();
            $table->boolean('active')->default(true)->index();
            $table->string('embedding_model');
            $table->unsignedInteger('embedding_dimensions');
            $table->timestamp('embedded_at')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'chunk_key']);
            $table->index(['profile_id', 'active', 'source_type'], 'profile_knowledge_active_source_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE profile_knowledge_chunks ADD COLUMN embedding vector(1536)');
            DB::statement("CREATE INDEX profile_knowledge_embedding_hnsw_idx ON profile_knowledge_chunks USING hnsw (embedding vector_cosine_ops) WHERE active = true AND visibility = 'public'");
        } else {
            Schema::table('profile_knowledge_chunks', function (Blueprint $table): void {
                $table->json('embedding')->nullable();
            });
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('profile_knowledge_chunks');
        Schema::dropIfExists('profile_knowledge_indexes');
    }
};
