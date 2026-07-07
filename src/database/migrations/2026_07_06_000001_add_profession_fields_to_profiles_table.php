<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->string('profession_key', 80)->default('custom')->after('personality')->index();
            $table->string('profession_template_version', 40)->default('2026-07')->after('profession_key');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropColumn(['profession_key', 'profession_template_version']);
        });
    }
};
