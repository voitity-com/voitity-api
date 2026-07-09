<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('voice_provider_requests', function (Blueprint $table) {
            $table->string('source_voice_id', 128)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('voice_provider_requests', function (Blueprint $table) {
            $table->dropColumn('source_voice_id');
        });
    }
};
