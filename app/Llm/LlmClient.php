<?php

namespace App\Llm;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Клиент к локальной нейросети.
 *
 * И Ollama, и LM Studio отдают OpenAI-совместимый API, поэтому код здесь
 * один на оба случая — различается только базовый адрес из config/llm.php.
 */
class LlmClient
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $apiKey = null,
    ) {
    }

    public function baseUrl(): string
    {
        if ($this->baseUrl) {
            return rtrim($this->baseUrl, '/');
        }

        $driver = config('llm.driver');
        $url = config("llm.drivers.{$driver}.base_url");

        if (! $url) {
            throw new LlmException("Неизвестный драйвер модели: {$driver}");
        }

        return rtrim($url, '/');
    }

    private function apiKey(): string
    {
        return $this->apiKey ?? (string) config('llm.drivers.'.config('llm.driver').'.api_key');
    }

    private function http(?int $timeout = null): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($this->apiKey())
            ->acceptJson()
            ->timeout($timeout ?? (int) config('llm.timeout'))
            ->connectTimeout(10);
    }

    /**
     * Один запрос к модели.
     *
     * @param  array  $messages  История в формате OpenAI: [['role' => 'user', 'content' => '...'], ...]
     * @param  array  $tools     Описания инструментов, которые модель может вызвать
     * @return array             Сообщение ассистента: ['content' => ..., 'tool_calls' => [...]]
     */
    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $payload = array_filter([
            'model'       => $options['model'] ?? config('llm.model'),
            'messages'    => $messages,
            'temperature' => $options['temperature'] ?? (float) config('llm.temperature'),
            'max_tokens'  => $options['max_tokens'] ?? (int) config('llm.max_tokens'),
            'tools'       => $tools ?: null,
            'stream'      => false,
        ], fn ($v) => $v !== null);

        $response = $this->withSlot(fn () => $this->http()->post('/chat/completions', $payload));

        if ($response->failed()) {
            Log::error('LLM chat failed', ['status' => $response->status(), 'body' => $response->body()]);

            throw new LlmException($this->humanError($response->status(), $response->body()));
        }

        $data = $response->json();
        $message = $data['choices'][0]['message'] ?? null;

        if (! is_array($message)) {
            throw new LlmException('Модель вернула пустой ответ. Проверьте, что модель загружена в '.config('llm.driver').'.');
        }

        return [
            'content'    => $this->stripThinking((string) ($message['content'] ?? '')),
            'tool_calls' => $message['tool_calls'] ?? [],
            'tokens'     => $data['usage']['total_tokens'] ?? null,
        ];
    }

    /**
     * Векторы для текстов. Векторизуем пачками — так в разы быстрее,
     * чем по одному куску за запрос.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $response = $this->withSlot(fn () => $this->http()->post('/embeddings', [
            'model' => config('llm.embedding_model'),
            'input' => array_values($texts),
        ]));

        if ($response->failed()) {
            Log::error('LLM embeddings failed', ['status' => $response->status(), 'body' => $response->body()]);

            throw new LlmException($this->humanError($response->status(), $response->body()));
        }

        $rows = $response->json('data') ?? [];

        // Порядок ответа формально соответствует порядку входа, но
        // подстраховываемся полем index — переставленные векторы
        // ломают поиск молча, и это потом не найти.
        $vectors = [];
        foreach ($rows as $i => $row) {
            $vectors[$row['index'] ?? $i] = $row['embedding'];
        }

        ksort($vectors);

        $expected = (int) config('llm.embedding_dimensions');
        $actual = count(reset($vectors) ?: []);

        if ($actual !== $expected) {
            throw new LlmException(
                "Модель эмбеддингов вернула вектор размерности {$actual}, а база рассчитана на {$expected}. ".
                "Поправьте LLM_EMBEDDING_DIMENSIONS в .env и переиндексируйте базу знаний."
            );
        }

        return array_values($vectors);
    }

    /** Список моделей, которые сейчас доступны в рантайме. */
    public function models(): array
    {
        $response = $this->http(15)->get('/models');

        if ($response->failed()) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($m) => $m['id'] ?? null,
            $response->json('data') ?? []
        )));
    }

    /** Жив ли рантайм. Используется на странице диагностики в админке. */
    public function ping(): array
    {
        try {
            $models = $this->models();

            return [
                'ok'       => true,
                'driver'   => config('llm.driver'),
                'base_url' => $this->baseUrl(),
                'models'   => $models,
                'chat_model_loaded'  => $this->modelPresent(config('llm.model'), $models),
                'embed_model_loaded' => $this->modelPresent(config('llm.embedding_model'), $models),
            ];
        } catch (\Throwable $e) {
            return [
                'ok'       => false,
                'driver'   => config('llm.driver'),
                'base_url' => $this->baseUrl(),
                'error'    => $e->getMessage(),
            ];
        }
    }

    /**
     * Ollama называет модель без тега «all-minilm», а в списке отдаёт
     * «all-minilm:latest». Сравниваем без тега, иначе диагностика
     * пугает администратора отсутствием загруженной модели.
     */
    private function modelPresent(string $model, array $models): bool
    {
        $normalize = fn (string $name) => strtolower(preg_replace('/:latest$/', '', $name));

        return in_array($normalize($model), array_map($normalize, $models), true);
    }

    /**
     * Пропускаем к модели не больше LLM_MAX_CONCURRENT запросов разом.
     * Локальная модель на одной видеокарте всё равно считает по очереди,
     * но без ограничения десять одновременных запросов съедают память
     * и валят рантайм целиком.
     */
    private function withSlot(callable $callback)
    {
        $slots = max(1, (int) config('llm.max_concurrent'));
        $deadline = microtime(true) + (int) config('llm.timeout');

        while (microtime(true) < $deadline) {
            for ($i = 0; $i < $slots; $i++) {
                $lock = Cache::lock("llm:slot:{$i}", (int) config('llm.timeout') + 30);

                if ($lock->get()) {
                    try {
                        return $callback();
                    } finally {
                        $lock->release();
                    }
                }
            }

            usleep(250_000);
        }

        throw new LlmException('Модель сейчас перегружена другими запросами. Попробуйте ещё раз через минуту.');
    }

    /**
     * Рассуждающие модели (Qwen3 и подобные) заворачивают ход мыслей
     * в <think>...</think>. Пользователю это показывать не нужно.
     */
    private function stripThinking(string $content): string
    {
        $clean = preg_replace('/<think>.*?<\/think>/is', '', $content);

        return trim($clean ?? $content);
    }

    private function humanError(int $status, string $body): string
    {
        if ($status === 404 && str_contains($body, 'model')) {
            return 'Модель "'.config('llm.model').'" не найдена в '.config('llm.driver').
                   '. Загрузите её командой: docker compose exec ollama ollama pull '.config('llm.model');
        }

        return "Нейросеть ответила ошибкой {$status}. Подробности в логах приложения.";
    }
}
