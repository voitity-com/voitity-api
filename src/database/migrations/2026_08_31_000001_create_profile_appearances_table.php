<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_appearances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('template_key', 40)->default('profile01');
            $table->string('background_type', 20)->default('css');
            $table->string('background_image_disk', 40)->nullable();
            $table->string('background_image_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_appearances');
    }
};
