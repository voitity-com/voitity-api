<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('profile_avatars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('profile_id')->constrained()->onDelete('cascade');
            $table->foreignId('aiimage_id')->nullable()->constrained('aiimages')->onDelete('set null');
            $table->foreignId('ai_video_id')->nullable()->constrained('aivideos')->onDelete('set null');
            $table->string('file', 255)->nullable();
            $table->string('status', 50)->default('active');
            $table->softDeletes();
            $table->timestamps();

            $table->index('profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_avatars');
    }
};
