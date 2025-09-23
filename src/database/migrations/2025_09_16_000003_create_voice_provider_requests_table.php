<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('voice_provider_requests', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('voice_id')->nullable();
            $table->bigInteger('voice_sample_id')->nullable();
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
