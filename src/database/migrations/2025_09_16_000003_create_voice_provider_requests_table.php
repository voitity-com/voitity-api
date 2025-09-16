<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('voice_provider_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voice_id')->constrained('voices')->onDelete('cascade');
            $table->foreignId('voice_sample_id')->constrained('voice_samples')->onDelete('cascade');
            $table->string('source', 100);
            $table->string('request_url', 255);
            $table->text('response')->nullable();
            $table->string('status', 50)->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_provider_requests');
    }
};
