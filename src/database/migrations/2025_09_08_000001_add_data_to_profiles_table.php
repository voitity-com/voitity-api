<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->json('data')->after('personality')->default(json_encode([
                'me' => new stdClass(),
                'work' => new stdClass(),
                'projects' => new stdClass(),
            ]));
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('data');
        });
    }
};
