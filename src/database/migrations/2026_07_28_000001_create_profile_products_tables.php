<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->boolean('products_enabled')->default(false)->after('networks')->index();
        });

        Schema::create('profile_product_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename', 255);
            $table->char('file_hash', 64);
            $table->string('status', 40)->default('previewed')->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->json('summary')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'file_hash']);
        });

        Schema::create('profile_products', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_product_import_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id', 191)->nullable();
            $table->string('slug', 180);
            $table->string('name', 180);
            $table->text('description');
            $table->string('image_source', 40);
            $table->string('image_url', 2048);
            $table->string('storage_disk', 80)->nullable();
            $table->string('storage_path', 2048)->nullable();
            $table->string('destination_type', 40)->index();
            $table->string('destination_url', 2048)->nullable();
            $table->string('country_code', 8)->nullable();
            $table->string('phone_number', 32)->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->char('fingerprint', 64);
            $table->timestamp('published_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'slug']);
            $table->unique(['profile_id', 'fingerprint']);
            $table->index(['profile_id', 'status', 'id']);
        });

        Schema::create('profile_product_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_product_import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('payload');
            $table->char('fingerprint', 64)->nullable()->index();
            $table->string('status', 40)->index();
            $table->foreignId('duplicate_product_id')->nullable()->constrained('profile_products')->nullOnDelete();
            $table->foreignId('duplicate_row_id')->nullable()->constrained('profile_product_import_rows')->nullOnDelete();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->unique(['profile_product_import_id', 'row_number'], 'profile_product_import_row_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_product_import_rows');
        Schema::dropIfExists('profile_products');
        Schema::dropIfExists('profile_product_imports');

        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropIndex(['products_enabled']);
            $table->dropColumn('products_enabled');
        });
    }
};
