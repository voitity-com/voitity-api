<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_products', function (Blueprint $table): void {
            $table->dropUnique(['profile_id', 'fingerprint']);
            $table->index(['profile_id', 'fingerprint'], 'profile_products_profile_fingerprint_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX profile_products_profile_external_id_unique
                ON profile_products (profile_id, LOWER(external_id))
                WHERE external_id IS NOT NULL'
            );

            return;
        }

        Schema::table('profile_products', function (Blueprint $table): void {
            $table->unique(['profile_id', 'external_id'], 'profile_products_profile_external_id_unique');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS profile_products_profile_external_id_unique');
        } else {
            Schema::table('profile_products', function (Blueprint $table): void {
                $table->dropUnique('profile_products_profile_external_id_unique');
            });
        }

        Schema::table('profile_products', function (Blueprint $table): void {
            $table->dropIndex('profile_products_profile_fingerprint_index');
            $table->unique(['profile_id', 'fingerprint']);
        });
    }
};
