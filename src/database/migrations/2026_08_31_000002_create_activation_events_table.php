<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 64);
            $table->string('utm_source', 255)->nullable();
            $table->string('utm_medium', 255)->nullable();
            $table->string('utm_campaign', 255)->nullable();
            $table->string('utm_term', 255)->nullable();
            $table->string('utm_content', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('occurred_at');
            $table->string('idempotency_key', 191)->unique();
            $table->timestampsTz();

            $table->index(['event_type', 'occurred_at'], 'activation_events_type_time_index');
            $table->index(['user_id', 'event_type', 'occurred_at'], 'activation_events_user_type_time_index');
            $table->index(['profile_id', 'event_type', 'occurred_at'], 'activation_events_profile_type_time_index');
            $table->index(['utm_campaign', 'occurred_at'], 'activation_events_campaign_time_index');
        });

        Schema::table('profiles', function (Blueprint $table): void {
            $table->boolean('products_enabled')->default(true)->change();
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->boolean('products_enabled')->default(false)->change();
        });

        Schema::dropIfExists('activation_events');
    }
};
