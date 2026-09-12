<?php

namespace App\Agent;

use App\Attachments\AttachmentProcessor;
use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Models\ChatMessage;
use App\Models\ChatAttachment;
use App\Models\ChatThread;
use App\Models\Setting;
use App\Rag\Retriever;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Один ответ агента на одно сообщение пользователя.
 *
 * Цикл простой: спросили модель → если она захотела инструмент, выполнили
 * и вернули результат → спросили снова. И так до готового ответа или до
 * упора в лимит шагов.
 */
class AgentRunner
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly ToolRegistry $tools,
        private readonly PromptBuilder $prompts,
        private readonly Retriever $retriever,
        private readonly AttachmentProcessor $attachments,
    ) {
    }

    /**
     * @param  iterable<ChatAttachment>  $attachments  файлы, приложенные к вопросу
     */
    public function answer(ChatThread $thread, string $question, iterable $attachments = []): ChatMessage
    {
        $startedAt = microtime(true);

        $attachments = collect($attachments);
        $images = $attachments->where('kind', ChatAttachment::KIND_IMAGE)
            ->filter(fn (ChatAttachment $a) => $a->exists());

        // Текст из документов и расшифровки голосовых приклеиваем к вопросу:
        // для модели это часть того, что спросил человек.
        $extracted = $this->attachments->textContext($attachments);

        $prompt = trim($question);

        if ($extracted !== '') {
            $prompt = trim($prompt === ''
                ? "Пользователь приложил файлы:\n\n".$extracted
                : $prompt."\n\nПриложенные файлы:\n\n".$extracted);
        }

        if ($prompt === '' && $images->isEmpty()) {
            $prompt = 'Пользователь отправил сообщение без текста.';
        }

        // Поиск по базе знаний ведём по тому, что человек спросил словами.
        // Содержимое приложенного договора как поисковый запрос бесполезно.
        $searchQuery = trim($question) !== ''
            ? trim($question)
            : Str::limit($this->attachments->searchableText($attachments), 400, '');

        // Инструментам, которые работают с личной памятью, сообщаем,
        // чья это память.
        foreach ($this->tools->available() as $tool) {
            if (method_exists($tool, 'forUser')) {
                $tool->forUser($thread->user);
            }
        }

        // Прямую команду «запомни …» выполняем сами, не надеясь на модель.
        // Небольшие модели инструменты вызывают через раз, а просьба
        // запомнить — слишком явная, чтобы её терять.
        $noted = $this->rememberIfAsked($thread, $question);

        if ($noted !== null) {
            $toolLog[] = ['tool' => 'remember (прямая команда)', 'arguments' => ['note' => $noted],
                          'result' => 'заметка сохранена'];
        }

        $messages = $this->history($thread, $prompt);

        if ($noted !== null) {
            $messages[] = [
                'role'    => 'system',
                'content' => 'Заметка «'.$noted.'» уже сохранена в личную память сотрудника. '
                           .'Коротко подтверди это и не вызывай remember повторно.',
            ];
        }
        $tools = $this->tools->available();
        $definitions = $this->tools->definitions();

        $collectedSources = [];
        $toolLog = [];
        $tokens = null;

        // Ищем по базе знаний сразу, не дожидаясь, пока модель догадается
        // вызвать инструмент. Небольшие модели часто не догадываются вовсе,
        // а вопрос почти всегда про документы компании. Инструмент при этом
        // остаётся: модель может доискать, если нужного не хватило.
        if (config('rag.auto_context') && trim($searchQuery) !== '') {
            $found = $this->retriever->search($searchQuery);

            if ($found !== []) {
                $blocks = [];

                foreach ($found as $i => $row) {
                    $number = $i + 1;
                    $blocks[] = "[{$number}] {$row['title']} (источник: {$row['source']})\n{$row['content']}";

                    $collectedSources[] = [
                        'title'   => $row['title'],
                        'source'  => $row['source'],
                        'uri'     => $row['uri'],
                        'score'   => $row['score'],
                        'excerpt' => Str::limit($row['content'], 300),
                    ];
                }

                array_splice($messages, 1, 0, [[
                    'role'    => 'system',
                    'content' => "Найдено в документах компании по текущему вопросу. "
                               ."Отвечай, опираясь на это, и указывай, из какого документа взят ответ. "
                               ."Если здесь ответа нет — так и скажи или поищи иначе.\n\n"
                               .implode("\n\n---\n\n", $blocks),
                ]]);

                $toolLog[] = ['tool' => 'knowledge_search (автоматически)', 'arguments' => ['query' => $searchQuery],
                              'result' => 'фрагментов: '.count($found)];
            }
        }

        try {
            // Картинки понимает только модель со зрением, и она, как правило,
            // не умеет вызывать инструменты. Поэтому для сообщения с картинкой
            // делаем один прямой запрос к ней — с уже найденным контекстом,
            // но без инструментов.
            if ($images->isNotEmpty()) {
                return $this->answerWithImages($thread, $messages, $prompt, $images, $collectedSources, $toolLog, $startedAt);
            }

            for ($step = 0; $step < (int) config('llm.max_tool_iterations'); $step++) {
                $reply = $this->llm->chat($messages, $definitions);
                $tokens = $reply['tokens'] ?? $tokens;

                if (empty($reply['tool_calls'])) {
                    return $this->store($thread, $reply['content'], $collectedSources, $toolLog, $tokens, $startedAt);
                }

                $messages[] = [
                    'role'       => 'assistant',
                    'content'    => $reply['content'] ?: null,
                    'tool_calls' => $reply['tool_calls'],
                ];

                foreach ($reply['tool_calls'] as $call) {
                    $name = $call['function']['name'] ?? '';
                    $arguments = $this->decodeArguments($call['function']['arguments'] ?? '{}');

                    if (! isset($tools[$name])) {
                        $output = "Инструмента {$name} не существует. Доступны: ".implode(', ', array_keys($tools));
                    } else {
                        try {
                            $result = $tools[$name]->execute($arguments);
                            $output = $result['output'];

                            foreach ($result['sources'] ?? [] as $source) {
                                $collectedSources[] = $source;
                            }
                        } catch (\Throwable $e) {
                            Log::error('Инструмент агента упал', ['tool' => $name, 'error' => $e->getMessage()]);
                            $output = 'Инструмент завершился ошибкой: '.$e->getMessage();
                        }
                    }

                    $toolLog[] = ['tool' => $name, 'arguments' => $arguments, 'result' => Str::limit($output, 500)];

                    $messages[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $call['id'] ?? Str::uuid()->toString(),
                        'name'         => $name,
                        'content'      => Str::limit($output, 24000),
                    ];
                }
            }

            // Шаги кончились, а модель всё ещё ходит по инструментам.
            // Просим финальный ответ по тому, что уже собрано.
            $messages[] = [
                'role'    => 'user',
                'content' => 'Хватит искать. Ответь по существу тем, что уже нашёл. '
                           .'Если данных не хватило — прямо скажи, чего именно не хватает.',
            ];

            $final = $this->llm->chat($messages);

            return $this->store($thread, $final['content'], $collectedSources, $toolLog, $final['tokens'] ?? $tokens, $startedAt);
        } catch (LlmException $e) {
            return $this->store($thread, '⚠️ '.$e->getMessage(), [], $toolLog, null, $startedAt);
        } catch (\Throwable $e) {
            report($e);

            return $this->store(
                $thread,
                '⚠️ Не получилось обработать запрос. Попробуйте ещё раз, а если повторится — покажите администратору.',
                [],
                $toolLog,
                null,
                $startedAt
            );
        }
    }

    /**
     * Явная команда запомнить.
     *
     * Ловим только начало сообщения («запомни, что …», «запиши: …») —
     * этого достаточно, чтобы понять намерение, и почти невозможно
     * задеть обычный вопрос.
     *
     * @return string|null  текст сохранённой заметки
     */
    private function rememberIfAsked(ChatThread $thread, string $question): ?string
    {
        $user = $thread->user;

        if (! $user) {
            return null;
        }

        if (! preg_match('/^\s*(?:запомни|запиши)\b[\s,:—-]*(?:что|чтобы)?\s*(.+)$/iu', trim($question), $m)) {
            return null;
        }

        $note = trim($m[1], " \t\n.;:");

        if (mb_strlen($note) < 3) {
            return null;
        }

        $user->rememberNote($note);

        return $note;
    }

    /**
     * Ответ на сообщение с картинками.
     *
     * @param  \Illuminate\Support\Collection<int, ChatAttachment>  $images
     */
    private function answerWithImages(
        ChatThread $thread,
        array $messages,
        string $prompt,
        $images,
        array $sources,
        array $toolLog,
        float $startedAt
    ): ChatMessage {
        $model = trim((string) config('llm.vision_model'));

        if ($model === '') {
            $names = $images->pluck('original_name')->implode(', ');

            return $this->store(
                $thread,
                "Картинки я посмотреть не могу: модель со зрением не подключена ({$names}). "
                ."Попросите администратора указать LLM_VISION_MODEL в настройках — например qwen2.5vl. "
                ."Если на картинке текст, его можно прислать файлом или написать сообщением.",
                $sources,
                $toolLog,
                null,
                $startedAt
            );
        }

        // Последнее сообщение (вопрос пользователя) пересобираем в формат
        // с картинками: текст и изображения идут отдельными частями.
        $parts = [['type' => 'text', 'text' => $prompt !== '' ? $prompt : 'Что на этом изображении?']];

        foreach ($images as $image) {
            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $image->asDataUrl()]];
        }

        $messages[count($messages) - 1] = ['role' => 'user', 'content' => $parts];

        $toolLog[] = [
            'tool'      => 'просмотр изображений',
            'arguments' => ['модель' => $model, 'файлов' => $images->count()],
            'result'    => 'изображения переданы модели',
        ];

        $reply = $this->llm->chat($messages, [], ['model' => $model]);

        return $this->store($thread, $reply['content'], $sources, $toolLog, $reply['tokens'] ?? null, $startedAt);
    }

    /**
     * История диалога для модели.
     *
     * Берём последние сообщения, а не всю переписку: у локальной модели
     * окно контекста небольшое, а на длинном контексте она ещё и думает
     * ощутимо дольше.
     */
    private function history(ChatThread $thread, string $question): array
    {
        $limit = (int) Setting::get('history_limit', 12);

        $messages = [['role' => 'system', 'content' => $this->prompts->build($thread->user)]];

        $previous = $thread->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->latest('id')
            ->take($limit)
            ->get()
            ->reverse();

        foreach ($previous as $message) {
            $messages[] = ['role' => $message->role, 'content' => $message->content];
        }

        $messages[] = ['role' => 'user', 'content' => $question];

        return $messages;
    }

    /**
     * Аргументы инструмента модель присылает строкой с JSON.
     * Небольшие модели иногда добавляют вокруг лишний текст —
     * вытаскиваем то, что похоже на объект.
     */
    private function decodeArguments(string|array $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $raw, $m)) {
            return json_decode($m[0], true) ?: [];
        }

        return [];
    }

    private function store(
        ChatThread $thread,
        string $content,
        array $sources,
        array $toolLog,
        ?int $tokens,
        float $startedAt
    ): ChatMessage {
        // Один документ мог попасть в ответ несколькими кусками —
        // в списке источников показываем его один раз.
        $unique = collect($sources)
            ->unique(fn ($s) => ($s['title'] ?? '').'|'.($s['uri'] ?? ''))
            ->values()
            ->all();

        $message = $thread->messages()->create([
            'role'        => 'assistant',
            'content'     => trim($content) !== '' ? $content : 'Не удалось сформулировать ответ. Переспросите, пожалуйста.',
            'sources'     => $unique ?: null,
            'tool_calls'  => $toolLog ?: null,
            'tokens'      => $tokens,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        $thread->update(['last_message_at' => now()]);

        return $message;
    }
}
