<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->default('Новый чат');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_thread_id')->constrained()->cascadeOnDelete();
            $table->string('role');                  // user | assistant | system
            $table->longText('content');
            $table->json('sources')->nullable();     // на чём основан ответ — показываем пользователю ссылки
            $table->json('tool_calls')->nullable();  // что агент дёргал по пути — для отладки и доверия
            $table->unsignedInteger('tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['chat_thread_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_threads');
    }
};
