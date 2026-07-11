<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aiimages', function (Blueprint $table) {
            $table->string('failure_code', 100)->nullable()->after('file');
            $table->text('failure_reason')->nullable()->after('failure_code');
        });

        Schema::table('aivideos', function (Blueprint $table) {
            $table->string('failure_code', 100)->nullable()->after('file');
            $table->text('failure_reason')->nullable()->after('failure_code');
        });

        Schema::table('profile_avatars', function (Blueprint $table) {
            $table->string('failure_code', 100)->nullable()->after('status');
            $table->text('failure_reason')->nullable()->after('failure_code');
        });
    }

    public function down(): void
    {
        Schema::table('profile_avatars', function (Blueprint $table) {
            $table->dropColumn(['failure_code', 'failure_reason']);
        });

        Schema::table('aivideos', function (Blueprint $table) {
            $table->dropColumn(['failure_code', 'failure_reason']);
        });

        Schema::table('aiimages', function (Blueprint $table) {
            $table->dropColumn(['failure_code', 'failure_reason']);
        });
    }
};
