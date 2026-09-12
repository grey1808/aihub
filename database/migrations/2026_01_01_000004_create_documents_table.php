<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_source_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');                 // путь к файлу / id страницы Confluence / ключ строки БД
            $table->string('title');
            $table->text('uri')->nullable();               // куда человек может пойти и посмотреть оригинал
            $table->string('mime')->nullable();
            $table->string('content_hash', 64);            // чтобы не переиндексировать то, что не менялось
            $table->longText('content')->nullable();
            $table->json('meta')->nullable();
            $table->string('index_status')->default('pending'); // pending | indexed | failed
            $table->text('index_error')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['knowledge_source_id', 'external_id']);
            $table->index('index_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
