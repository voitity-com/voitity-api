<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_leads', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable()->after('no_response_at');
            $table->index(['business_id', 'updated_at']);
        });

        DB::table('business_leads')
            ->select(['id', 'created_at'])
            ->orderBy('id')
            ->chunkById(200, function ($leads): void {
                foreach ($leads as $lead) {
                    $alreadyRecorded = DB::table('business_lead_status_histories')
                        ->where('business_lead_id', $lead->id)
                        ->whereNull('from_status')
                        ->where('to_status', 'created')
                        ->exists();

                    if (! $alreadyRecorded) {
                        DB::table('business_lead_status_histories')->insert([
                            'business_lead_id' => $lead->id,
                            'changed_by_user_id' => null,
                            'from_status' => null,
                            'to_status' => 'created',
                            'note' => null,
                            'created_at' => $lead->created_at,
                            'updated_at' => $lead->created_at,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('business_leads', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'updated_at']);
            $table->dropColumn('closed_at');
        });
    }
};
