<?php

use App\Enums\ProfileSourceStatus;
use App\Enums\ProfileSourceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40)->default(ProfileSourceType::Manual->value)->index();
            $table->string('name', 150);
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->string('storage_path')->nullable();
            $table->string('status', 40)->default(ProfileSourceStatus::Uploaded->value)->index();
            $table->longText('extracted_text')->nullable();
            $table->string('parser_version', 40)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_sources');
    }
};
