<?php

namespace App\Console\Commands;

use App\Attachments\SpeechToText;
use App\Llm\LlmClient;
use App\Models\Document;
use App\Models\KnowledgeSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Одна команда, чтобы понять, почему «ничего не работает».
 * Первое, что стоит запустить на моноблоке при любой жалобе.
 */
class DiagnoseCommand extends Command
{
    protected $signature = 'aihub:diagnose';

    protected $description = 'Проверить, что база, очередь и нейросеть на месте';

    public function handle(LlmClient $llm, SpeechToText $speech): int
    {
        $ok = true;

        $this->line('');
        $this->line('<comment>База данных</comment>');

        try {
            DB::select('SELECT 1');
            $this->info('  подключение — есть');

            DB::select("SELECT '[1]'::vector(1)");
            $this->info('  расширение pgvector — установлено');
        } catch (\Throwable $e) {
            $this->error('  ошибка: '.$e->getMessage());
            $ok = false;
        }

        $this->line('');
        $this->line('<comment>Нейросеть ('.config('llm.driver').')</comment>');

        $ping = $llm->ping();
        $this->line('  адрес: '.$ping['base_url']);

        if (! $ping['ok']) {
            $this->error('  недоступна: '.($ping['error'] ?? 'неизвестная ошибка'));
            $ok = false;
        } else {
            $this->info('  отвечает, моделей загружено: '.count($ping['models']));

            $chat = config('llm.model');
            $embed = config('llm.embedding_model');

            $ping['chat_model_loaded']
                ? $this->info("  модель для диалога «{$chat}» — на месте")
                : $this->error("  модель для диалога «{$chat}» НЕ загружена. Выполните: ollama pull {$chat}");

            if ($ping['embeddings_separate'] ?? false) {
                $this->line('  вектора считает отдельный сервис: '.$ping['embedding_base_url']);
            }

            $ping['embed_model_loaded']
                ? $this->info("  модель для векторов «{$embed}» — на месте")
                : $this->error("  модель для векторов «{$embed}» НЕ загружена по адресу "
                              .($ping['embedding_base_url'] ?? '').". Загрузите её там.");

            $ok = $ok && $ping['chat_model_loaded'] && $ping['embed_model_loaded'];
        }

        $this->line('');
        $this->line('<comment>Драйверы подключения к чужим базам</comment>');

        $drivers = \PDO::getAvailableDrivers();

        in_array('pgsql', $drivers, true)
            ? $this->info('  PostgreSQL: есть')
            : $this->error('  PostgreSQL: НЕТ — это обязательный драйвер');

        if (in_array('sqlsrv', $drivers, true)) {
            $this->info('  Microsoft SQL Server: есть — базу 1С на MS SQL подключить можно');
        } else {
            $this->warn('  Microsoft SQL Server: нет');
            $this->line('     Это не ошибка. Драйвер нужен только для 1С на MS SQL напрямую.');
            $this->line('     Через OData и на PostgreSQL всё работает без него.');
        }

        $this->line('  Клиент Redis: '.config('database.redis.client')
                   .(extension_loaded('redis') ? '' : ' (расширения phpredis нет, работаем на чистом PHP)'));

        $this->line('');
        $this->line('<comment>Вложения в чате</comment>');

        $vision = trim((string) config('llm.vision_model'));

        if ($vision === '') {
            $this->warn('  зрение: модель не указана — картинки принимаются, но помощник их не видит');
            $this->line('     чтобы включить: ollama pull qwen2.5vl:7b, затем LLM_VISION_MODEL=qwen2.5vl:7b в .env');
        } elseif (! empty($ping['models']) && $this->modelListed($vision, $ping['models'])) {
            $this->info("  зрение: модель «{$vision}» — на месте");
        } else {
            $this->error("  зрение: модель «{$vision}» указана, но НЕ загружена. Выполните: ollama pull {$vision}");
            $ok = false;
        }

        $stt = $speech->ping();

        if (! config('attachments.stt.enabled')) {
            $this->warn('  голос: распознавание выключено (STT_ENABLED=false)');
        } elseif ($stt['ok']) {
            $this->info('  голос: сервис распознавания отвечает ('.config('attachments.stt.model').')');
        } else {
            $this->error('  голос: сервис распознавания недоступен — '.($stt['error'] ?? ''));
            $this->line('     запустить: docker compose --profile with-whisper up -d');
            $ok = false;
        }

        $this->line('  вложений сохранено: '.\App\Models\ChatAttachment::count()
                   .' (не отправленных: '.\App\Models\ChatAttachment::whereNull('chat_message_id')->count().')');

        $this->line('');
        $this->line('<comment>База знаний</comment>');
        $this->line('  источников включено: '.KnowledgeSource::where('is_enabled', true)->count());
        $this->line('  документов: '.Document::count());
        $this->line('  ждут индексации: '.Document::where('index_status', 'pending')->count());
        $this->line('  с ошибкой индексации: '.Document::where('index_status', 'failed')->count());
        $this->line('  фрагментов с векторами: '.DB::table('document_chunks')->whereNotNull('embedding')->count());

        $this->line('');
        $this->line('<comment>Очередь</comment>');
        $this->line('  задач в ожидании: '.DB::table('jobs')->count());
        $this->line('  упавших задач: '.DB::table('failed_jobs')->count());

        $this->line('');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** Ollama отдаёт модели с тегом «:latest» — сравниваем без него. */
    private function modelListed(string $model, array $models): bool
    {
        $normalize = fn (string $name) => strtolower(preg_replace('/:latest$/', '', $name));

        return in_array($normalize($model), array_map($normalize, $models), true);
    }
}
