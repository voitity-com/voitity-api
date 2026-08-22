<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_leads', function (Blueprint $table): void {
            $table->timestamp('read_at')->nullable()->after('status');
            $table->index(['business_id', 'read_at'], 'business_leads_business_read_index');
        });
    }

    public function down(): void
    {
        Schema::table('business_leads', function (Blueprint $table): void {
            $table->dropIndex('business_leads_business_read_index');
            $table->dropColumn('read_at');
        });
    }
};
