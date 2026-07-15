<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_integrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40)->index();
            $table->string('provider_user_id', 120)->nullable()->index();
            $table->string('username', 150)->nullable();
            $table->text('access_token')->nullable();
            $table->string('token_type', 40)->nullable();
            $table->json('scopes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('status', 40)->default('connected')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_integrations');
    }
};
