<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 50)->default('wompi');
            $table->string('reference', 100)->unique();
            $table->string('provider_transaction_id', 100)->nullable();
            $table->string('plan', 50);
            $table->decimal('display_amount_usd', 10, 2);
            $table->string('display_currency', 3)->default('USD');
            $table->decimal('exchange_rate', 12, 4);
            $table->decimal('amount_cop', 12, 2);
            $table->unsignedBigInteger('amount_in_cents');
            $table->string('currency', 3)->default('COP');
            $table->string('status', 50)->default('pending');
            $table->string('wompi_status', 50)->nullable();
            $table->text('checkout_url')->nullable();
            $table->json('raw_provider_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['provider', 'provider_transaction_id']);
            $table->index(['plan', 'status']);
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 50)->default('wompi');
            $table->string('provider_event_id', 100)->nullable();
            $table->string('event_type', 100);
            $table->string('checksum', 128)->nullable();
            $table->boolean('is_valid_signature')->default(false);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id']);
            $table->index(['payment_order_id', 'event_type']);
            $table->index(['provider', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payment_orders');
    }
};
