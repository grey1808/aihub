<?php

namespace Tests\Unit;

use App\Llm\LlmClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmbeddingEndpointTest extends TestCase
{
    private function fakeVector(): array
    {
        return ['data' => [['index' => 0, 'embedding' => array_fill(0, 384, 0.1)]]];
    }

    public function test_по_умолчанию_вектора_считаются_там_же_где_диалог(): void
    {
        config([
            'llm.drivers.ollama.base_url' => 'http://chat-host:11434/v1',
            'llm.embedding_base_url'      => '',
            'llm.embedding_dimensions'    => 384,
        ]);

        Http::fake(['*' => Http::response($this->fakeVector())]);

        app(LlmClient::class)->embed(['текст']);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://chat-host:11434/v1'));
    }

    public function test_отдельный_адрес_для_векторов_используется_когда_задан(): void
    {
        // На моноблоке диалог идёт на LM Studio, а вектора — на Ollama:
        // LM Studio обрабатывает запросы по одному, и индексация иначе
        // забивала бы очередь чата.
        config([
            'llm.driver'                    => 'lmstudio',
            'llm.drivers.lmstudio.base_url' => 'http://lmstudio:1234/v1',
            'llm.embedding_base_url'        => 'http://ollama:11434/v1',
            'llm.embedding_dimensions'      => 384,
        ]);

        Http::fake(['*' => Http::response($this->fakeVector())]);

        $client = app(LlmClient::class);

        $this->assertTrue($client->embeddingsAreSeparate());
        $this->assertSame('http://ollama:11434/v1', $client->embeddingBaseUrl());

        $client->embed(['текст']);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://ollama:11434/v1'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'lmstudio'));
    }

    public function test_несовпадение_размерности_вектора_ловится_сразу(): void
    {
        // Иначе поиск молча перестаёт находить, и причину ищут неделю.
        config(['llm.embedding_dimensions' => 1024, 'llm.embedding_base_url' => '']);

        Http::fake(['*' => Http::response($this->fakeVector())]);

        $this->expectExceptionMessageMatches('/размерности 384.*рассчитана на 1024/u');

        app(LlmClient::class)->embed(['текст']);
    }
}
