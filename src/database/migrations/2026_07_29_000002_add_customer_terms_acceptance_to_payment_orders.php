<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_orders', function (Blueprint $table): void {
            $table->string('customer_terms_version', 32)->nullable()->after('billing_reason');
            $table->timestamp('customer_terms_accepted_at')->nullable()->after('customer_terms_version');
            $table->decimal('accepted_plan_price_usd', 10, 2)->nullable()->after('customer_terms_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'customer_terms_version',
                'customer_terms_accepted_at',
                'accepted_plan_price_usd',
            ]);
        });
    }
};
