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

        Schema::table('business_knowledge_chunks', function (Blueprint $table): void {
            $table->char('content_hash', 64)->nullable()->index();
            $table->boolean('active')->default(true)->index();
            $table->string('embedding_model')->nullable();
            $table->unsignedInteger('embedding_dimensions')->nullable();
            $table->timestamp('embedded_at')->nullable();
            $table->index(['business_id', 'active'], 'business_knowledge_active_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE business_knowledge_chunks ADD COLUMN embedding vector(1536)');
            DB::statement('CREATE INDEX business_knowledge_embedding_hnsw_idx ON business_knowledge_chunks USING hnsw (embedding vector_cosine_ops) WHERE active = true');
        } else {
            Schema::table('business_knowledge_chunks', function (Blueprint $table): void {
                $table->json('embedding')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS business_knowledge_embedding_hnsw_idx');
            DB::statement('ALTER TABLE business_knowledge_chunks DROP COLUMN IF EXISTS embedding');
        } else {
            Schema::table('business_knowledge_chunks', function (Blueprint $table): void {
                $table->dropColumn('embedding');
            });
        }

        Schema::table('business_knowledge_chunks', function (Blueprint $table): void {
            $table->dropIndex('business_knowledge_active_idx');
            $table->dropColumn(['content_hash', 'active', 'embedding_model', 'embedding_dimensions', 'embedded_at']);
        });
    }
};
