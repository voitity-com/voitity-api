<?php

use App\Enums\ProfileFactVisibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('profile_source_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 80)->index();
            $table->text('text');
            $table->string('visibility', 40)->default(ProfileFactVisibility::Public->value)->index();
            $table->boolean('approved')->default(false)->index();
            $table->boolean('indexed')->default(false)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_facts');
    }
};
