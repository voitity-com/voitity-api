<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale', 5)->default('en')->after('email');
            $table->string('email_verification_token', 64)->nullable()->after('email_verified_at');
            $table->timestamp('email_verification_sent_at')->nullable()->after('email_verification_token');
            $table->timestamp('email_verification_expires_at')->nullable()->after('email_verification_sent_at');
            $table->index('email_verification_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['email_verification_token']);
            $table->dropColumn([
                'locale',
                'email_verification_token',
                'email_verification_sent_at',
                'email_verification_expires_at',
            ]);
        });
    }
};
