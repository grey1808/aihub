<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_attachments', function (Blueprint $table) {
            $table->id();

            // Файл сначала загружается сам по себе, и только потом
            // привязывается к сообщению — поэтому связь необязательная.
            $table->foreignId('chat_message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('chat_thread_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('kind');                    // image | audio | document
            $table->string('original_name');
            $table->string('path');                    // путь внутри приватного диска
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);

            // Что удалось извлечь: текст документа, расшифровка голоса.
            $table->longText('extracted_text')->nullable();

            $table->string('status')->default('pending'); // pending | ready | failed
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'chat_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_attachments');
    }
};
