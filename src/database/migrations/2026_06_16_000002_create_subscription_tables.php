<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('plan', 50)->default('starter');
            $table->timestamp('started_at');
            $table->timestamp('renews_at');
            $table->string('status', 50)->default('first');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'active']);
            $table->index(['status', 'renews_at']);
        });

        Schema::create('subscription_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('period_started_at');
            $table->timestamp('period_renews_at');
            $table->unsignedInteger('profiles_remaining')->default(0);
            $table->unsignedInteger('avatar_images_remaining')->default(0);
            $table->unsignedInteger('avatar_video_seconds_remaining')->default(0);
            $table->unsignedInteger('voice_clones_remaining')->default(0);
            $table->unsignedInteger('tts_characters_remaining')->default(0);
            $table->unsignedInteger('chat_messages_remaining')->default(0);
            $table->timestamps();

            $table->unique('subscription_id');
            $table->index('user_id');
        });

        Schema::create('subscription_uses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('usage_type', 100);
            $table->string('source_type', 150)->nullable();
            $table->string('source_id', 100)->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->unsignedInteger('profiles_used')->default(0);
            $table->unsignedInteger('avatar_images_used')->default(0);
            $table->unsignedInteger('avatar_video_seconds_used')->default(0);
            $table->unsignedInteger('voice_clones_used')->default(0);
            $table->unsignedInteger('tts_characters_used')->default(0);
            $table->unsignedInteger('chat_messages_used')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('used_at');
            $table->timestamps();

            $table->index(['user_id', 'usage_type']);
            $table->index(['profile_id', 'usage_type']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_uses');
        Schema::dropIfExists('subscription_limits');
        Schema::dropIfExists('subscriptions');
    }
};
