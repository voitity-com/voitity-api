<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voices', function (Blueprint $table) {
            $table->string('language_code', 10)->default('es')->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('voices', function (Blueprint $table) {
            $table->dropColumn('language_code');
        });
    }
};
