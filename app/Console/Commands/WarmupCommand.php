<?php

namespace App\Console\Commands;

use App\Llm\LlmClient;
use Illuminate\Console\Command;

/**
 * Прогрев моделей.
 *
 * Первый запрос после запуска долгий: модель поднимается с диска в память,
 * и для большой модели это десятки секунд. Ждать этого должен не первый
 * сотрудник, пришедший утром, а служба при старте.
 */
class WarmupCommand extends Command
{
    protected $signature = 'aihub:warmup {--quiet-fail : Не считать ошибкой, если модель недоступна}';

    protected $description = 'Загрузить модели в память, чтобы первый вопрос не ждал';

    public function handle(LlmClient $llm): int
    {
        $ok = true;

        $this->task('модель для векторов', function () use ($llm) {
            $llm->embed(['прогрев']);

            return true;
        }, $ok);

        $this->task('модель для диалога', function () use ($llm) {
            $llm->chat([['role' => 'user', 'content' => 'привет']], [], ['max_tokens' => 8]);

            return true;
        }, $ok);

        return $ok || $this->option('quiet-fail') ? self::SUCCESS : self::FAILURE;
    }

    private function task(string $name, callable $callback, bool &$ok): void
    {
        $startedAt = microtime(true);

        try {
            $callback();
            $seconds = round(microtime(true) - $startedAt, 1);
            $this->info("  {$name}: готова за {$seconds} с");
        } catch (\Throwable $e) {
            $ok = false;
            $this->warn("  {$name}: прогреть не удалось — ".$e->getMessage());
        }
    }
}
