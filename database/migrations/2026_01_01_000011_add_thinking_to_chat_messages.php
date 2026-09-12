<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            // Ход мыслей модели. Храним, чтобы он не пропадал после
            // перезагрузки страницы — иногда именно по нему видно,
            // почему помощник ответил именно так.
            $table->longText('thinking')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('thinking');
        });
    }
};
