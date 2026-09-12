<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');                      // ключ коннектора: local_files, smb, confluence, onec_sql, onec_odata
            $table->text('description')->nullable();     // подсказка агенту: что здесь лежит и когда сюда смотреть
            $table->boolean('is_enabled')->default(true);
            $table->json('config')->nullable();          // поля из формы коннектора, секреты внутри шифруются
            $table->unsignedInteger('sync_interval_minutes')->default(60);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_status')->nullable();   // ok | error | running | never
            $table->text('last_error')->nullable();
            $table->unsignedInteger('documents_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_sources');
    }
};
