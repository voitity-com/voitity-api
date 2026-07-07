<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_source_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->string('type', 80)->default('document')->index();
            $table->string('title', 150)->nullable();
            $table->text('content');
            $table->json('structured_data')->nullable();
            $table->decimal('confidence', 5, 2)->default(0);
            $table->boolean('approved')->default(false)->index();
            $table->boolean('indexed')->default(false)->index();
            $table->string('source_url', 2048)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_source_items');
    }
};
