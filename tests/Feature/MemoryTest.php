<?php

namespace Tests\Feature;

use App\Agent\PromptBuilder;
use App\Agent\Tools\ForgetTool;
use App\Agent\Tools\RememberTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MemoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_профиль_попадает_в_системное_сообщение(): void
    {
        $user = User::factory()->create([
            'name'           => 'Смирнова Ольга Петровна',
            'preferred_name' => 'Ольга Петровна',
            'position'       => 'Главный бухгалтер',
            'department'     => 'Бухгалтерия',
        ]);

        $prompt = app(PromptBuilder::class)->build($user);

        $this->assertStringContainsString('Ольга Петровна', $prompt);
        $this->assertStringContainsString('Главный бухгалтер', $prompt);
        $this->assertStringContainsString('Бухгалтерия', $prompt);
    }

    public function test_без_обращения_используется_имя_из_регистрации(): void
    {
        $user = User::factory()->create(['name' => 'Иванов Иван', 'preferred_name' => null]);

        $this->assertSame('Иванов Иван', $user->callName());
        $this->assertStringContainsString('Иванов Иван', app(PromptBuilder::class)->build($user));
    }

    public function test_заметки_читаются_перед_каждым_ответом(): void
    {
        $user = User::factory()->create(['memory' => "- Отчёты нужны таблицей\n- Веду ООО «Ромашка»"]);

        $prompt = app(PromptBuilder::class)->build($user);

        $this->assertStringContainsString('Отчёты нужны таблицей', $prompt);
        $this->assertStringContainsString('Веду ООО «Ромашка»', $prompt);
    }

    public function test_пустая_память_не_добавляет_лишнего_в_промпт(): void
    {
        $user = User::factory()->create(['memory' => null]);

        $this->assertStringNotContainsString('ТВОИ ЗАМЕТКИ', app(PromptBuilder::class)->build($user));
    }

    public function test_инструмент_remember_дописывает_заметку(): void
    {
        $user = User::factory()->create(['memory' => '- Уже была заметка']);

        $tool = app(RememberTool::class);
        $tool->forUser($user);
        $tool->execute(['note' => 'Работает по графику 2/2']);

        $memory = $user->fresh()->memory;

        $this->assertStringContainsString('Уже была заметка', $memory);
        $this->assertStringContainsString('Работает по графику 2/2', $memory);
        $this->assertNotNull($user->fresh()->memory_updated_at);
    }

    public function test_инструмент_forget_убирает_только_нужную_строку(): void
    {
        $user = User::factory()->create([
            'memory' => "- Отчёты нужны таблицей\n- Веду ООО «Ромашка»\n- В отпуске в октябре",
        ]);

        $tool = app(ForgetTool::class);
        $tool->forUser($user);
        $tool->execute(['about' => 'Ромашка']);

        $memory = $user->fresh()->memory;

        $this->assertStringNotContainsString('Ромашка', $memory);
        $this->assertStringContainsString('Отчёты нужны таблицей', $memory);
        $this->assertStringContainsString('В отпуске в октябре', $memory);
    }

    public function test_forget_со_словом_всё_очищает_память(): void
    {
        $user = User::factory()->create(['memory' => "- Первая\n- Вторая"]);

        $tool = app(ForgetTool::class);
        $tool->forUser($user);
        $tool->execute(['about' => 'всё']);

        $this->assertNull($user->fresh()->memory);
    }

    public function test_прямая_команда_запомни_срабатывает_без_вызова_инструмента(): void
    {
        // Небольшие модели инструменты вызывают через раз, поэтому явную
        // команду «запомни …» приложение выполняет само.
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Запомнил.']]],
        ])]);

        $user = User::factory()->create();
        $thread = $user->threads()->create(['title' => 'Новый чат']);

        $this->actingAs($user)
            ->postJson("/chat/{$thread->id}/send", ['message' => 'Запомни, что я в отпуске с 1 октября'])
            ->assertOk();

        $this->assertStringContainsString('я в отпуске с 1 октября', $user->fresh()->memory);
    }

    public function test_обычный_вопрос_ничего_не_записывает_в_память(): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Ответ.']]],
        ])]);

        $user = User::factory()->create();
        $thread = $user->threads()->create(['title' => 'Новый чат']);

        $this->actingAs($user)
            ->postJson("/chat/{$thread->id}/send", ['message' => 'Когда сдавать авансовый отчёт?'])
            ->assertOk();

        $this->assertNull($user->fresh()->memory);
    }

    public function test_сотрудник_правит_свои_заметки_в_профиле(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile/memory', ['memory' => '- Новая заметка руками'])
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('- Новая заметка руками', $user->fresh()->memory);
    }

    public function test_должность_сохраняется_из_профиля(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/profile', [
            'name'           => 'Иванов Иван',
            'preferred_name' => 'Иван',
            'position'       => 'Кладовщик',
            'department'     => 'Склад',
            'email'          => $user->email,
        ])->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Иван', $user->preferred_name);
        $this->assertSame('Кладовщик', $user->position);
        $this->assertSame('Кладовщик, Склад', $user->role());
    }
}
