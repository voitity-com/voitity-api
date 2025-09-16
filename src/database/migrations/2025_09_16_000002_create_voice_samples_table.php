<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('voice_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voice_id')->constrained('voices')->onDelete('cascade');
            $table->string('file', 255);
            $table->integer('duration');
            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_samples');
    }
};
