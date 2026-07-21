<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_submissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email');
            $table->string('phone_country_code', 8);
            $table->string('phone_number', 32);
            $table->text('message');
            $table->string('locale', 2)->default('en');
            $table->string('source', 80)->default('landing_page');
            $table->timestamp('consent_accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->text('notification_error')->nullable();
            $table->timestamps();

            $table->index(['email', 'created_at']);
            $table->index('created_at');
            $table->index('notified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_submissions');
    }
};
