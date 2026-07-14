<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_conversation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('type', 64);
            $table->text('text')->nullable();
            $table->text('audio_url')->nullable();
            $table->string('audio_path')->nullable();
            $table->string('audio_disk', 64)->nullable();
            $table->string('audio_source', 32)->nullable();
            $table->string('audio_format', 16)->nullable();
            $table->foreignId('voice_id')->nullable()->constrained('voices')->nullOnDelete();
            $table->string('status', 32)->default('ready');
            $table->string('text_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'type'], 'profile_conversation_messages_profile_type_unique');
            $table->index(['profile_id', 'status'], 'profile_conversation_messages_profile_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_conversation_messages');
    }
};
