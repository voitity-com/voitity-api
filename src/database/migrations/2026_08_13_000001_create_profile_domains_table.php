<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('hostname', 253)->unique();
            $table->string('status', 40)->default('pending_provisioning')->index();
            $table->string('provider', 40);
            $table->string('provider_tenant_id')->nullable()->unique();
            $table->string('provider_tenant_arn')->nullable();
            $table->string('routing_endpoint')->nullable();
            $table->string('certificate_arn')->nullable();
            $table->string('certificate_status', 40)->nullable();
            $table->string('dns_status', 40)->nullable();
            $table->json('dns_records')->nullable();
            $table->string('provider_status', 60)->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_domains');
    }
};
