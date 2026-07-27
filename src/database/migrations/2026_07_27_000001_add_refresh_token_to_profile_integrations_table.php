<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_integrations', function (Blueprint $table): void {
            if (! Schema::hasColumn('profile_integrations', 'refresh_token')) {
                $table->text('refresh_token')->nullable()->after('access_token');
            }

            if (! Schema::hasColumn('profile_integrations', 'refresh_expires_at')) {
                $table->timestamp('refresh_expires_at')->nullable()->after('expires_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('profile_integrations', function (Blueprint $table): void {
            if (Schema::hasColumn('profile_integrations', 'refresh_expires_at')) {
                $table->dropColumn('refresh_expires_at');
            }

            if (Schema::hasColumn('profile_integrations', 'refresh_token')) {
                $table->dropColumn('refresh_token');
            }
        });
    }
};
