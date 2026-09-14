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

    /** Адрес, по которому считаем вектора. Может отличаться от адреса диалога. */
    public function embeddingBaseUrl(): string
    {
        $url = trim((string) config('llm.embedding_base_url'));

        return $url !== '' ? rtrim($url, '/') : $this->baseUrl();
    }

    private function embeddingHttp(): PendingRequest
    {
        $url = trim((string) config('llm.embedding_base_url'));

        if ($url === '') {
            return $this->http();
        }

        return Http::baseUrl(rtrim($url, '/'))
            ->withToken((string) (config('llm.embedding_api_key') ?: 'local'))
            ->acceptJson()
            ->timeout((int) config('llm.timeout'))
            ->connectTimeout(10);
    }

    /** Считаем ли вектора отдельным сервисом. */
    public function embeddingsAreSeparate(): bool
    {
        return trim((string) config('llm.embedding_base_url')) !== '';
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

        $response = $this->withSlot(
            fn () => $this->http($options['timeout'] ?? null)->post('/chat/completions', $payload)
        );

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
            'thinking'   => trim((string) ($message['reasoning'] ?? $message['reasoning_content'] ?? ''))
                            ?: $this->extractThinking((string) ($message['content'] ?? '')),
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

        // Если эмбеддинги считает отдельный сервис, очередь к модели диалога
        // им занимать не нужно — они друг другу не мешают.
        $request = fn () => $this->embeddingHttp()->post('/embeddings', [
            'model' => config('llm.embedding_model'),
            'input' => array_values($texts),
        ]);

        $response = $this->embeddingsAreSeparate() ? $request() : $this->withSlot($request);

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

    /**
     * Потоковый запрос: ответ приходит кусочками, по мере того как модель
     * его придумывает. Ради этого всё и затевалось — локальная модель
     * пишет ответ десятки секунд, и смотреть всё это время на пустой экран
     * невыносимо.
     *
     * @param  callable(string $type, string $text): void  $onChunk
     *         вызывается на каждый кусок: type — 'thinking' или 'content'
     * @return array{content:string,thinking:string,tool_calls:array}
     */
    public function stream(array $messages, array $tools, callable $onChunk, array $options = []): array
    {
        $payload = array_filter([
            'model'       => $options['model'] ?? config('llm.model'),
            'messages'    => $messages,
            'temperature' => $options['temperature'] ?? (float) config('llm.temperature'),
            'max_tokens'  => $options['max_tokens'] ?? (int) config('llm.max_tokens'),
            'tools'       => $tools ?: null,
            'stream'      => true,
        ], fn ($v) => $v !== null);

        $content = '';
        $thinking = '';
        $toolCalls = [];

        // Рассуждающие модели заворачивают ход мыслей в <think>…</think>.
        // Тег приходит по кускам, поэтому режем поток на лету.
        $inThinking = false;
        $buffer = '';

        $handle = function (string $piece) use (&$content, &$thinking, &$inThinking, &$buffer, $onChunk) {
            $buffer .= $piece;

            while ($buffer !== '') {
                if ($inThinking) {
                    $end = strpos($buffer, '</think>');

                    if ($end === false) {
                        // Хвост может оказаться началом закрывающего тега —
                        // придержим его до следующего куска.
                        $safe = $this->keepTail($buffer, '</think>');
                        $thinking .= $safe;

                        if ($safe !== '') {
                            $onChunk('thinking', $safe);
                        }

                        $buffer = substr($buffer, strlen($safe));

                        return;
                    }

                    $part = substr($buffer, 0, $end);
                    $thinking .= $part;

                    if ($part !== '') {
                        $onChunk('thinking', $part);
                    }

                    $buffer = substr($buffer, $end + strlen('</think>'));
                    $inThinking = false;

                    continue;
                }

                $start = strpos($buffer, '<think>');

                if ($start === false) {
                    $safe = $this->keepTail($buffer, '<think>');
                    $content .= $safe;

                    if ($safe !== '') {
                        $onChunk('content', $safe);
                    }

                    $buffer = substr($buffer, strlen($safe));

                    return;
                }

                $part = substr($buffer, 0, $start);
                $content .= $part;

                if ($part !== '') {
                    $onChunk('content', $part);
                }

                $buffer = substr($buffer, $start + strlen('<think>'));
                $inThinking = true;
            }
        };

        $response = $this->withSlot(fn () => $this->http()->withOptions([
            'stream' => true,
        ])->post('/chat/completions', $payload));

        if ($response->failed()) {
            Log::error('LLM stream failed', ['status' => $response->status()]);

            throw new LlmException($this->humanError($response->status(), (string) $response->body()));
        }

        $body = $response->toPsrResponse()->getBody();
        $line = '';

        while (! $body->eof()) {
            $chunk = $body->read(4096);

            if ($chunk === '') {
                continue;
            }

            $line .= $chunk;

            while (($pos = strpos($line, "\n")) !== false) {
                $raw = trim(substr($line, 0, $pos));
                $line = substr($line, $pos + 1);

                if ($raw === '' || ! str_starts_with($raw, 'data:')) {
                    continue;
                }

                $data = trim(substr($raw, 5));

                if ($data === '[DONE]') {
                    break 2;
                }

                $json = json_decode($data, true);

                if (! is_array($json)) {
                    continue;
                }

                $delta = $json['choices'][0]['delta'] ?? [];

                if (isset($delta['content']) && $delta['content'] !== '') {
                    $handle((string) $delta['content']);
                }

                // Мысли приходят по-разному: Ollama кладёт их в reasoning,
                // некоторые сборки — в reasoning_content, а часть моделей
                // просто оборачивает в <think> внутри content (это выше).
                foreach (['reasoning', 'reasoning_content'] as $field) {
                    if (isset($delta[$field]) && $delta[$field] !== '') {
                        $thinking .= $delta[$field];
                        $onChunk('thinking', (string) $delta[$field]);
                    }
                }

                foreach ($delta['tool_calls'] ?? [] as $call) {
                    $this->mergeToolCall($toolCalls, $call);
                }
            }
        }

        // Остаток буфера, если поток кончился на полуслове.
        if ($buffer !== '') {
            if ($inThinking) {
                $thinking .= $buffer;
                $onChunk('thinking', $buffer);
            } else {
                $content .= $buffer;
                $onChunk('content', $buffer);
            }
        }

        return [
            'content'    => trim($content),
            'thinking'   => trim($thinking),
            'tool_calls' => array_values($toolCalls),
        ];
    }

    /**
     * Отдаём то, что точно можно отдать сразу, придерживая хвост,
     * который может оказаться началом тега <think> или </think>.
     */
    private function keepTail(string $buffer, string $tag): string
    {
        $max = min(strlen($tag) - 1, strlen($buffer));

        for ($i = $max; $i > 0; $i--) {
            if (str_starts_with($tag, substr($buffer, -$i))) {
                return substr($buffer, 0, strlen($buffer) - $i);
            }
        }

        return $buffer;
    }

    /** Вызов инструмента приходит по частям — собираем его обратно. */
    private function mergeToolCall(array &$calls, array $piece): void
    {
        $index = $piece['index'] ?? count($calls);

        $calls[$index] ??= ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];

        if (! empty($piece['id'])) {
            $calls[$index]['id'] = $piece['id'];
        }

        if (! empty($piece['function']['name'])) {
            $calls[$index]['function']['name'] = $piece['function']['name'];
        }

        if (isset($piece['function']['arguments'])) {
            $calls[$index]['function']['arguments'] .= $piece['function']['arguments'];
        }
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

    /** Модели на сервисе эмбеддингов. */
    public function embeddingModels(): array
    {
        if (! $this->embeddingsAreSeparate()) {
            return $this->models();
        }

        try {
            $response = $this->embeddingHttp()->timeout(15)->get('/models');
        } catch (\Throwable) {
            return [];
        }

        return $response->failed() ? [] : array_values(array_filter(array_map(
            fn ($m) => $m['id'] ?? null,
            $response->json('data') ?? []
        )));
    }

    /**
     * Сколько текста модель видит за раз — по данным самой Ollama.
     *
     * Спрашиваем не из любопытства: по умолчанию окно 4096 токенов,
     * и найденные документы туда не помещаются. Лишнее обрезается молча,
     * и выглядит это как «модель не видит документы».
     *
     * У LM Studio такого запроса нет — там вернём null.
     *
     * @return array<string, int>  модель => размер окна
     */
    public function contextLengths(): array
    {
        return array_map(fn (array $m) => $m['context_length'], $this->loadedModels());
    }

    /**
     * Что сейчас загружено в Ollama и чем считается.
     *
     * Поле size_vram — самое ценное: ноль означает, что модель молотится
     * на процессоре. Для модели на 30 миллиардов параметров это разница
     * между «ответ за 10 секунд» и «ответ за 6 минут».
     *
     * @return array<string, array{context_length:int,size:int,size_vram:int}>
     */
    public function loadedModels(): array
    {
        if (config('llm.driver') !== 'ollama') {
            return [];
        }

        // Нативный адрес Ollama лежит рядом с OpenAI-совместимым: /v1 убираем.
        $base = preg_replace('#/v1/?$#', '', $this->baseUrl());

        try {
            $response = Http::baseUrl($base)->timeout(10)->get('/api/ps');
        } catch (\Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $result = [];

        foreach ($response->json('models') ?? [] as $model) {
            if (! isset($model['name'])) {
                continue;
            }

            $result[$model['name']] = [
                'context_length' => (int) ($model['context_length'] ?? 0),
                'size'           => (int) ($model['size'] ?? 0),
                'size_vram'      => (int) ($model['size_vram'] ?? 0),
            ];
        }

        return $result;
    }

    /** Жив ли рантайм. Используется на странице диагностики в админке. */
    public function ping(): array
    {
        try {
            $models = $this->models();

            $embeddingModels = $this->embeddingModels();

            return [
                'ok'       => true,
                'driver'   => config('llm.driver'),
                'base_url' => $this->baseUrl(),
                'models'   => $models,
                'embedding_base_url' => $this->embeddingBaseUrl(),
                'embeddings_separate' => $this->embeddingsAreSeparate(),
                'chat_model_loaded'  => $this->modelPresent(config('llm.model'), $models),
                'embed_model_loaded' => $this->modelPresent(config('llm.embedding_model'), $embeddingModels),
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

    /** Ход мыслей, если модель завернула его в <think>…</think>. */
    private function extractThinking(string $content): string
    {
        return preg_match('/<think>(.*?)<\/think>/is', $content, $m) ? trim($m[1]) : '';
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
