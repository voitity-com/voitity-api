<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('feature_flags')->where('key', 'ai.use_embeddings')->delete();
    }

    public function down(): void
    {
        // Embedding retrieval is mandatory; the obsolete flag must not be restored.
    }
};
