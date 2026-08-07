<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_sources', function (Blueprint $table): void {
            $table->char('content_hash', 64)->nullable()->after('parser_version')->index();
            $table->foreignId('duplicate_of_source_id')
                ->nullable()
                ->after('content_hash')
                ->constrained('profile_sources')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('profile_sources', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('duplicate_of_source_id');
            $table->dropIndex(['content_hash']);
            $table->dropColumn('content_hash');
        });
    }
};
