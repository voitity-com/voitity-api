<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_limits', function (Blueprint $table): void {
            $table->decimal('credits_remaining', 14, 6)->default(0)->change();
        });

        Schema::table('subscription_uses', function (Blueprint $table): void {
            $table->decimal('credits_used', 14, 6)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_limits', function (Blueprint $table): void {
            $table->decimal('credits_remaining', 10, 2)->default(0)->change();
        });

        Schema::table('subscription_uses', function (Blueprint $table): void {
            $table->decimal('credits_used', 10, 2)->default(0)->change();
        });
    }
};
