<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_leads', function (Blueprint $table): void {
            $table->string('whatsapp', 80)->nullable()->after('phone');
            $table->string('website')->nullable()->after('company');
        });
    }

    public function down(): void
    {
        Schema::table('business_leads', function (Blueprint $table): void {
            $table->dropColumn(['whatsapp', 'website']);
        });
    }
};
