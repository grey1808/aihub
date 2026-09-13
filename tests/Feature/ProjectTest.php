<?php

namespace Tests\Feature;

use App\Agent\PromptBuilder;
use App\Models\ChatThread;
use App\Models\KnowledgeSource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_проект_создаётся_и_чат_ложится_в_него(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/projects', ['name' => 'Квартальный отчёт'])
            ->assertRedirect();

        $project = Project::first();
        $this->assertSame('Квартальный отчёт', $project->name);

        $this->actingAs($user)->post('/chat', ['project_id' => $project->id]);

        $this->assertSame($project->id, ChatThread::first()->project_id);
    }

    public function test_инструкция_и_заметки_проекта_попадают_в_промпт(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create([
            'name'         => 'Квартальный отчёт',
            'description'  => 'Отчётность за 3 квартал',
            'instructions' => 'Суммы приводи с НДС.',
            'notes'        => '- Сверка на последний день месяца',
        ]);

        $prompt = app(PromptBuilder::class)->build($user, $project);

        $this->assertStringContainsString('Квартальный отчёт', $prompt);
        $this->assertStringContainsString('Суммы приводи с НДС', $prompt);
        $this->assertStringContainsString('Сверка на последний день месяца', $prompt);
    }

    public function test_чаты_одного_проекта_не_видят_переписку_друг_друга(): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Ответ.']]],
        ])]);

        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Проект']);

        $first = $user->threads()->create(['project_id' => $project->id, 'title' => 'Первый']);
        $first->messages()->create(['role' => 'user', 'content' => 'СЕКРЕТНАЯ ФРАЗА ИЗ ПЕРВОГО ЧАТА']);

        $second = $user->threads()->create(['project_id' => $project->id, 'title' => 'Второй']);

        $this->actingAs($user)->postJson("/chat/{$second->id}/send", ['message' => 'Вопрос'])->assertOk();

        // Общий контекст проекта — да, чужая переписка — нет.
        Http::assertSent(function ($request) {
            return ! str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'СЕКРЕТНАЯ ФРАЗА');
        });
    }

    public function test_поиск_ограничивается_источниками_проекта(): void
    {
        $user = User::factory()->create();

        $allowed = KnowledgeSource::create(['name' => 'Бухгалтерия', 'type' => 'local_files', 'config' => []]);
        $other = KnowledgeSource::create(['name' => 'Кадры', 'type' => 'local_files', 'config' => []]);

        $project = $user->projects()->create(['name' => 'Проект', 'source_ids' => [$allowed->id]]);

        $this->assertSame([$allowed->id], $project->sourceIds());

        $prompt = app(PromptBuilder::class)->build($user, $project);

        $this->assertStringContainsString('Бухгалтерия', $prompt);
        $this->assertStringNotContainsString('Кадры', $prompt);
    }

    public function test_чужой_проект_недоступен(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $project = $owner->projects()->create(['name' => 'Личный проект']);

        $this->actingAs($stranger)->get(route('projects.show', $project))->assertForbidden();
        $this->actingAs($stranger)->delete(route('projects.destroy', $project))->assertForbidden();
    }

    public function test_удаление_проекта_не_удаляет_чаты(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Проект']);
        $thread = $user->threads()->create(['project_id' => $project->id, 'title' => 'Чат']);

        $this->actingAs($user)->delete(route('projects.destroy', $project))->assertRedirect();

        $this->assertNull(Project::find($project->id));
        $this->assertNotNull($thread->fresh());
        $this->assertNull($thread->fresh()->project_id);
    }

    public function test_чат_переносится_между_проектами_и_обратно(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Проект']);
        $thread = $user->threads()->create(['title' => 'Чат']);

        $this->actingAs($user)->patch(route('chat.move', $thread), ['project_id' => $project->id]);
        $this->assertSame($project->id, $thread->fresh()->project_id);

        $this->actingAs($user)->patch(route('chat.move', $thread), ['project_id' => null]);
        $this->assertNull($thread->fresh()->project_id);
    }

    public function test_чат_нельзя_перенести_в_чужой_проект(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $foreign = $stranger->projects()->create(['name' => 'Чужой']);
        $thread = $user->threads()->create(['title' => 'Чат']);

        $this->actingAs($user)->patch(route('chat.move', $thread), ['project_id' => $foreign->id]);

        $this->assertNull($thread->fresh()->project_id);
    }

    public function test_существующие_чаты_добавляются_в_проект_списком(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Проект']);

        $first = $user->threads()->create(['title' => 'Первый']);
        $second = $user->threads()->create(['title' => 'Второй']);
        $untouched = $user->threads()->create(['title' => 'Третий']);

        $this->actingAs($user)
            ->post(route('projects.attach', $project), ['threads' => [$first->id, $second->id]])
            ->assertRedirect();

        $this->assertSame($project->id, $first->fresh()->project_id);
        $this->assertSame($project->id, $second->fresh()->project_id);
        $this->assertNull($untouched->fresh()->project_id);
    }

    public function test_чужой_чат_в_свой_проект_списком_не_добавить(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();

        $project = $user->projects()->create(['name' => 'Проект']);
        $foreign = $stranger->threads()->create(['title' => 'Чужой']);

        $this->actingAs($user)
            ->post(route('projects.attach', $project), ['threads' => [$foreign->id]])
            ->assertRedirect();

        $this->assertNull($foreign->fresh()->project_id);
    }

    public function test_в_чужой_проект_чаты_не_добавить(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();

        $foreignProject = $stranger->projects()->create(['name' => 'Чужой проект']);
        $thread = $user->threads()->create(['title' => 'Свой чат']);

        $this->actingAs($user)
            ->post(route('projects.attach', $foreignProject), ['threads' => [$thread->id]])
            ->assertForbidden();

        $this->assertNull($thread->fresh()->project_id);
    }

    public function test_перетаскивание_получает_ответ_данными_а_не_редиректом(): void
    {
        // Перетаскивание мышью ходит через fetch. Если ответить редиректом,
        // fetch пойдёт по нему тем же методом PATCH и упрётся в маршрут
        // переименования — тот ответит 422, и браузер покажет ошибку,
        // хотя перенос уже прошёл. Именно так и было.
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Проект']);
        $thread = $user->threads()->create(['title' => 'Чат']);

        $response = $this->actingAs($user)
            ->patchJson(route('chat.move', $thread), ['project_id' => $project->id]);

        $response->assertOk()->assertJsonPath('project_id', $project->id);

        $this->assertSame($project->id, $thread->fresh()->project_id);
    }

    public function test_обычная_форма_переноса_по_прежнему_редиректит(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Проект']);
        $thread = $user->threads()->create(['title' => 'Чат']);

        $this->actingAs($user)
            ->from(route('chat.show', $thread))
            ->patch(route('chat.move', $thread), ['project_id' => $project->id])
            ->assertRedirect(route('chat.show', $thread));
    }
}
