<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_uses', function (Blueprint $table): void {
            $table->index(
                ['status', 'reserved_at', 'id'],
                'subscription_uses_stale_reservations_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('subscription_uses', function (Blueprint $table): void {
            $table->dropIndex('subscription_uses_stale_reservations_index');
        });
    }
};
