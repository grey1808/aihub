<?php

namespace Tests\Feature;

use App\Llm\LlmClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StreamingTest extends TestCase
{
    use RefreshDatabase;

    /** Ответ рантайма в формате Server-Sent Events. */
    private function sse(array $chunks): string
    {
        $lines = array_map(
            fn (array $delta) => 'data: '.json_encode(['choices' => [['delta' => $delta]]], JSON_UNESCAPED_UNICODE),
            $chunks
        );

        return implode("\n\n", $lines)."\n\ndata: [DONE]\n\n";
    }

    public function test_текст_собирается_из_кусочков_по_порядку(): void
    {
        Http::fake(['*/chat/completions' => Http::response($this->sse([
            ['content' => 'За '], ['content' => '14 '], ['content' => 'дней.'],
        ]))]);

        $pieces = [];

        $result = app(LlmClient::class)->stream([], [], function (string $type, string $text) use (&$pieces) {
            $pieces[] = [$type, $text];
        });

        $this->assertSame('За 14 дней.', $result['content']);
        $this->assertSame([['content', 'За '], ['content', '14 '], ['content', 'дней.']], $pieces);
    }

    public function test_мысли_из_поля_reasoning_идут_отдельным_потоком(): void
    {
        // Ollama кладёт ход мыслей в reasoning, а не в content.
        Http::fake(['*/chat/completions' => Http::response($this->sse([
            ['reasoning' => 'Надо посчитать. '],
            ['reasoning' => 'Получается 8400.'],
            ['content' => 'Итого 8400 рублей.'],
        ]))]);

        $thinking = '';
        $content = '';

        $result = app(LlmClient::class)->stream([], [], function ($type, $text) use (&$thinking, &$content) {
            $type === 'thinking' ? $thinking .= $text : $content .= $text;
        });

        $this->assertSame('Надо посчитать. Получается 8400.', $thinking);
        $this->assertSame('Итого 8400 рублей.', $content);
        $this->assertSame('Итого 8400 рублей.', $result['content']);
    }

    public function test_мысли_в_теге_think_не_попадают_в_ответ(): void
    {
        // Другие модели заворачивают мысли прямо в content, причём тег
        // приходит разорванным между кусками.
        Http::fake(['*/chat/completions' => Http::response($this->sse([
            ['content' => '<thi'], ['content' => 'nk>Сначала посчитаю'], ['content' => '</think>'],
            ['content' => 'Ответ: 8400.'],
        ]))]);

        $thinking = '';
        $content = '';

        app(LlmClient::class)->stream([], [], function ($type, $text) use (&$thinking, &$content) {
            $type === 'thinking' ? $thinking .= $text : $content .= $text;
        });

        $this->assertSame('Сначала посчитаю', $thinking);
        $this->assertSame('Ответ: 8400.', $content);
    }

    public function test_вызов_инструмента_собирается_из_кусочков(): void
    {
        Http::fake(['*/chat/completions' => Http::response($this->sse([
            ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'knowledge_search', 'arguments' => '{"que']]]],
            ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'ry":"отпуск"}']]]],
        ]))]);

        $result = app(LlmClient::class)->stream([], [], fn () => null);

        $this->assertCount(1, $result['tool_calls']);
        $this->assertSame('knowledge_search', $result['tool_calls'][0]['function']['name']);
        $this->assertSame('{"query":"отпуск"}', $result['tool_calls'][0]['function']['arguments']);
    }

    public function test_поток_отдаёт_статусы_вопрос_и_готовый_ответ(): void
    {
        Http::fake(['*/chat/completions' => Http::response($this->sse([
            ['content' => 'Готовый ответ.'],
        ]))]);

        $user = User::factory()->create();
        $thread = $user->threads()->create(['title' => 'Новый чат']);

        $response = $this->actingAs($user)
            ->post("/chat/{$thread->id}/stream", ['message' => 'Когда отпуск?']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $response->assertHeader('X-Accel-Buffering', 'no');

        $body = $response->streamedContent();

        $this->assertStringContainsString('event: question', $body);
        $this->assertStringContainsString('event: delta', $body);
        $this->assertStringContainsString('event: done', $body);

        $this->assertSame('Готовый ответ.', $thread->messages()->where('role', 'assistant')->first()->content);
    }

    public function test_пустое_сообщение_в_поток_не_пускают(): void
    {
        $user = User::factory()->create();
        $thread = $user->threads()->create(['title' => 'Новый чат']);

        $this->actingAs($user)->post("/chat/{$thread->id}/stream", ['message' => '  '])
            ->assertStatus(422);
    }

    public function test_чужой_чат_в_поток_не_отдаётся(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $thread = $owner->threads()->create(['title' => 'Личное']);

        $this->actingAs($stranger)->post("/chat/{$thread->id}/stream", ['message' => 'Привет'])
            ->assertForbidden();
    }
}
