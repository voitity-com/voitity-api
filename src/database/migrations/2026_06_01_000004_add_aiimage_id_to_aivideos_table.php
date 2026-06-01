<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('aivideos', 'aiimage_id')) {
            Schema::table('aivideos', function (Blueprint $table) {
                $table->foreignId('aiimage_id')
                    ->nullable()
                    ->after('profile_id')
                    ->constrained('aiimages')
                    ->nullOnDelete();

                $table->unique('aiimage_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('aivideos', 'aiimage_id')) {
            Schema::table('aivideos', function (Blueprint $table) {
                $table->dropUnique(['aiimage_id']);
                $table->dropConstrainedForeignId('aiimage_id');
            });
        }
    }
};
