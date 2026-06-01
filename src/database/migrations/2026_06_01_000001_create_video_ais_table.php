<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('video_ais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('profile_id')->nullable()->constrained()->onDelete('set null');
            $table->string('source_id', 100);
            $table->string('source', 100);
            $table->string('file', 255);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['source', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_ais');
    }
};
