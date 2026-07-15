<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_integration_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40)->index();
            $table->string('provider_media_id', 120);
            $table->string('media_type', 40)->nullable();
            $table->string('media_url', 2048)->nullable();
            $table->string('thumbnail_url', 2048)->nullable();
            $table->string('permalink', 2048)->nullable();
            $table->text('caption')->nullable();
            $table->text('observation')->nullable();
            $table->boolean('selected')->default(false)->index();
            $table->timestamp('taken_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['profile_integration_id', 'provider_media_id'], 'profile_integration_media_provider_unique');
            $table->index(['profile_id', 'provider', 'selected'], 'profile_integration_media_profile_selected_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_integration_media');
    }
};
