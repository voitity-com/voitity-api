<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['chat_id', 'source'], 'messages_chat_id_source_index');
            $table->index(['chat_id', 'created_at'], 'messages_chat_id_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_chat_id_source_index');
            $table->dropIndex('messages_chat_id_created_at_index');
        });
    }
};
