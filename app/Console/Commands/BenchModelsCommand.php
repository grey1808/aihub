<?php

namespace App\Console\Commands;

use App\Agent\ToolRegistry;
use App\Llm\LlmClient;
use Illuminate\Console\Command;

/**
 * Сравнение моделей на живых вопросах.
 *
 * Выбирать модель по чужим тестам бессмысленно: важно, как она отвечает
 * на вопросы конкретной компании и вызывает ли инструменты — без этого
 * помощник не найдёт ни одного документа, каким бы умным ни был.
 */
class BenchModelsCommand extends Command
{
    protected $signature = 'aihub:bench
                            {--models= : Список моделей через запятую. По умолчанию — та, что настроена}
                            {--questions= : Файл с вопросами, по одному в строке}
                            {--repeat=1 : Сколько раз повторить каждый вопрос}';

    protected $description = 'Сравнить модели по скорости и умению вызывать инструменты';

    /** Вопросы по умолчанию: два требуют поиска, один — обычный разговор. */
    private const DEFAULT_QUESTIONS = [
        'За сколько дней нужно подать заявление на отпуск?',
        'Какие документы нужны для авансового отчёта по командировке?',
        'Объясни в двух предложениях, чем отличается аванс от задатка.',
    ];

    public function handle(LlmClient $llm, ToolRegistry $tools): int
    {
        $models = $this->models($llm);

        if ($models === []) {
            $this->error('Не указано ни одной модели, и в рантайме их не видно.');

            return self::FAILURE;
        }

        $questions = $this->questions();
        $repeat = max(1, (int) $this->option('repeat'));

        $this->line('');
        $this->line('Моделей: '.count($models).', вопросов: '.count($questions).', повторов: '.$repeat);
        $this->line('Инструменты передаются те же, что в реальной работе.');
        $this->line('');

        $definitions = $tools->definitions();
        $rows = [];

        foreach ($models as $model) {
            $this->line("<comment>{$model}</comment>");

            $ttft = [];
            $speed = [];
            $toolCalls = 0;
            $attempts = 0;
            $failures = 0;
            $sample = '';

            foreach ($questions as $question) {
                for ($i = 0; $i < $repeat; $i++) {
                    $attempts++;

                    try {
                        $result = $this->probe($llm, $model, $question, $definitions);
                    } catch (\Throwable $e) {
                        $failures++;
                        $this->line('  <fg=red>ошибка:</> '.$e->getMessage());

                        continue;
                    }

                    $ttft[] = $result['ttft'];
                    $speed[] = $result['speed'];
                    $toolCalls += $result['used_tool'] ? 1 : 0;
                    $sample = $sample ?: $result['text'];

                    $this->line(sprintf(
                        '  %s первый токен %.1f с, %.1f ток/с%s',
                        $result['used_tool'] ? 'инструмент ' : 'ответ       ',
                        $result['ttft'],
                        $result['speed'],
                        $result['used_tool'] ? '' : ''
                    ));
                }
            }

            $rows[] = [
                $model,
                $ttft ? sprintf('%.1f с', array_sum($ttft) / count($ttft)) : '—',
                $speed ? sprintf('%.1f', array_sum($speed) / count($speed)) : '—',
                $attempts > 0 ? round($toolCalls / $attempts * 100).'%' : '—',
                $failures > 0 ? (string) $failures : '',
            ];

            if ($sample !== '') {
                $this->line('  <fg=gray>пример: '.mb_substr(str_replace("\n", ' ', $sample), 0, 120).'…</>');
            }

            $this->line('');
        }

        $this->table(
            ['Модель', 'До первого токена', 'Токенов/с', 'Вызвала инструмент', 'Ошибок'],
            $rows
        );

        $this->line('');
        $this->line('«Вызвала инструмент» — доля вопросов, на которых модель пошла искать');
        $this->line('в базе знаний. Два вопроса из трёх по умолчанию требуют поиска,');
        $this->line('третий — нет, так что 100% здесь не цель. Важно, чтобы модель');
        $this->line('вообще умела вызывать инструменты: ноль означает, что помощник');
        $this->line('не найдёт ни одного документа.');
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Один вопрос к модели с замером времени до первого токена.
     *
     * Время до первого токена важнее скорости генерации: именно столько
     * человек смотрит на пустой экран, прежде чем что-то произойдёт.
     */
    private function probe(LlmClient $llm, string $model, string $question, array $definitions): array
    {
        $startedAt = microtime(true);
        $firstAt = null;
        $chars = 0;

        $result = $llm->stream(
            [
                ['role' => 'system', 'content' => 'Ты помощник сотрудников компании. Отвечай кратко, по-русски. '
                                                 .'Если вопрос про внутренние документы компании — ищи в базе знаний.'],
                ['role' => 'user', 'content' => $question],
            ],
            $definitions,
            function (string $type, string $text) use (&$firstAt, &$chars, $startedAt) {
                $firstAt ??= microtime(true);
                $chars += mb_strlen($text);
            },
            ['model' => $model]
        );

        $now = microtime(true);
        $ttft = ($firstAt ?? $now) - $startedAt;
        $generating = max(0.001, $now - ($firstAt ?? $now));

        // Примерно 3 символа на токен для русского текста — точного счётчика
        // в потоке нет, а для сравнения моделей между собой этого достаточно.
        $tokens = $chars / 3;

        return [
            'ttft'      => $ttft,
            'speed'     => $tokens / $generating,
            'used_tool' => ! empty($result['tool_calls']),
            'text'      => $result['content'] ?: '(модель сразу пошла в инструмент)',
        ];
    }

    private function models(LlmClient $llm): array
    {
        $option = trim((string) $this->option('models'));

        if ($option !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $option))));
        }

        return array_filter([config('llm.model')]);
    }

    private function questions(): array
    {
        $file = trim((string) $this->option('questions'));

        if ($file === '') {
            return self::DEFAULT_QUESTIONS;
        }

        if (! is_readable($file)) {
            $this->warn("Файл {$file} не читается, беру вопросы по умолчанию.");

            return self::DEFAULT_QUESTIONS;
        }

        $lines = array_values(array_filter(array_map('trim', file($file))));

        return $lines ?: self::DEFAULT_QUESTIONS;
    }
}
