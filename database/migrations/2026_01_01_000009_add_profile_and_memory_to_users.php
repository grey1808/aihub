<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Как к человеку обращаться. Необязательно: если пусто,
            // помощник зовёт по имени из регистрации.
            $table->string('preferred_name')->nullable()->after('name');

            // Должность и отдел. Помощник учитывает их, отвечая: бухгалтеру
            // и кладовщику на один и тот же вопрос нужны разные ответы.
            $table->string('position')->nullable()->after('preferred_name');
            $table->string('department')->nullable()->after('position');

            // Личная память помощника об этом сотруднике — markdown.
            // Читается перед каждым ответом, дополняется самим помощником
            // по просьбе «запомни» и правится руками в профиле.
            $table->longText('memory')->nullable()->after('department');
            $table->timestamp('memory_updated_at')->nullable()->after('memory');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['preferred_name', 'position', 'department', 'memory', 'memory_updated_at']);
        });
    }
};
