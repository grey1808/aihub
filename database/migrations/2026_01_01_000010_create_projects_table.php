<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // Как помощнику себя вести в этом проекте. Добавляется к общей
            // инструкции и действует во всех чатах проекта.
            $table->text('instructions')->nullable();

            // Память проекта: то, что помощник накопил по этой теме.
            // Читается перед каждым ответом в любом чате проекта.
            $table->longText('notes')->nullable();
            $table->timestamp('notes_updated_at')->nullable();

            // Ограничение поиска источниками. null — искать везде.
            $table->json('source_ids')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_used_at']);
        });

        Schema::table('chat_threads', function (Blueprint $table) {
            // Чат без проекта — это нормально: быстрый вопрос не обязан
            // никуда складываться. Удаление проекта чаты не уносит,
            // они просто выпадают из папки.
            $table->foreignId('project_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();

            // Мягкое удаление: чат уезжает в корзину и его можно вернуть.
            $table->softDeletes();

            $table->index(['user_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_threads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->dropSoftDeletes();
        });

        Schema::dropIfExists('projects');
    }
};
