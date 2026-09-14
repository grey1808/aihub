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

    /** @var array<int, string> то, без чего помощник не работает */
    private array $critical = [];

    /** @var array<int, string> то, что просто выключено или не настроено */
    private array $optional = [];

    public function handle(LlmClient $llm, SpeechToText $speech): int
    {

        $this->line('');
        $this->line('<comment>База данных</comment>');

        try {
            DB::select('SELECT 1');
            $this->info('  подключение — есть');

            DB::select("SELECT '[1]'::vector(1)");
            $this->info('  расширение pgvector — установлено');
        } catch (\Throwable $e) {
            $this->error('  ошибка: '.$e->getMessage());
            $this->critical[] = 'база данных недоступна или нет расширения pgvector';
        }

        $this->line('');
        $this->line('<comment>Нейросеть ('.config('llm.driver').')</comment>');

        $ping = $llm->ping();
        $this->line('  адрес: '.$ping['base_url']);

        if (! $ping['ok']) {
            $this->error('  недоступна: '.($ping['error'] ?? 'неизвестная ошибка'));
            $this->critical[] = 'нейросеть не отвечает по адресу '.$ping['base_url'];
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

            if (! $ping['chat_model_loaded']) {
                $this->critical[] = 'модель для диалога «'.$chat.'» не загружена';
            }

            if (! $ping['embed_model_loaded']) {
                $this->critical[] = 'модель для векторов «'.$embed.'» не загружена';
            }
        }

        // Мало знать, что модель в списке: она может быть не той, битой
        // или отдавать вектор другой длины. Проверяем живым вызовом —
        // именно на этом обычно и спотыкаются при первом запуске.
        if ($ping['ok']) {
            $this->line('');
            $this->line('<comment>Живая проверка</comment>');

            try {
                $startedAt = microtime(true);
                $vector = $llm->embed(['проверка связи'])[0] ?? [];
                $ms = (int) ((microtime(true) - $startedAt) * 1000);

                $this->info(sprintf(
                    '  вектор посчитан: длина %d, за %d мс',
                    count($vector),
                    $ms
                ));

                // Долгий первый вызов — это почти всегда загрузка модели
                // с диска, а не медленная работа. Пугать этим не нужно.
                if ($ms > 5000) {
                    $this->line('     Долго, потому что модель поднималась в память. Следующие вызовы быстрые.');
                    $this->line('     Чтобы этого не ждал первый сотрудник утром: OLLAMA_KEEP_ALIVE=-1 в .env.');
                }
            } catch (\Throwable $e) {
                $this->error('  вектор посчитать не удалось: '.$e->getMessage());
                $this->line('     Без этого база знаний не заработает: поиск по документам');
                $this->line('     держится именно на векторах.');
                $this->critical[] = 'вектора не считаются';
            }

            try {
                $startedAt = microtime(true);
                // Лимит с запасом: рассуждающие модели сначала думают,
                // и на коротком лимите весь ответ уходит в рассуждения,
                // а наружу приходит пустая строка.
                // Таймаут короткий намеренно: диагностика должна отвечать
                // быстро. Если модель не осилила два слова за две минуты,
                // проблема не в таймауте, и ждать дольше незачем.
                $reply = $llm->chat([
                    ['role' => 'user', 'content' => 'Ответь одним словом: работает?'],
                ], [], ['max_tokens' => 256, 'timeout' => 120]);
                $ms = (int) ((microtime(true) - $startedAt) * 1000);

                $answer = trim((string) $reply['content']);

                if ($answer !== '') {
                    $seconds = round($ms / 1000, 1);

                    if ($ms > 30000) {
                        // Несколько секунд — нормально. Минуты на короткий
                        // вопрос означают, что модель считается процессором.
                        $this->error("  модель ответила за {$seconds} с — это недопустимо долго");
                        $this->line('     Короткий вопрос должен занимать секунды. Скорее всего, модель');
                        $this->line('     считается процессором, а не видеокартой. Смотрите раздел ниже.');
                        $this->critical[] = "модель отвечает за {$seconds} с вместо секунд";
                    } else {
                        $this->info("  модель ответила за {$seconds} с: «".mb_substr($answer, 0, 60)."»");
                    }
                } elseif (trim((string) ($reply['thinking'] ?? '')) !== '') {
                    $this->warn("  модель отвечала {$ms} мс, но весь ответ ушёл в рассуждения.");
                    $this->line('     Связь есть. Если такое повторяется в чате — увеличьте LLM_MAX_TOKENS.');
                } else {
                    $this->error("  модель вернула пустой ответ за {$ms} мс.");
                    $this->critical[] = 'модель не отвечает';
                }
            } catch (\Throwable $e) {
                $timedOut = str_contains($e->getMessage(), 'imed out')
                         || str_contains($e->getMessage(), 'error 28');

                if ($timedOut) {
                    $this->error('  модель не ответила за 2 минуты на вопрос из двух слов');
                    $this->line('     Это не сетевая проблема: соединение есть, вектора считаются.');
                    $this->line('     Почти наверняка модель считается процессором или не помещается');
                    $this->line('     в память. Смотрите README, раздел «Модель считается процессором».');
                    $this->critical[] = 'модель не отвечает за 2 минуты — почти наверняка считается процессором';
                } else {
                    $this->error('  модель не ответила: '.$e->getMessage());
                    $this->critical[] = 'модель не отвечает: '.$e->getMessage();
                }
            }
        }

        // Размер окна проверяем отдельно: модель может быть загружена
        // и отвечать, но с окном, в которое найденные документы не влезают.
        $loaded = $llm->loadedModels();

        if (config('llm.driver') === 'ollama') {
            $this->line('');
            $this->line('<comment>Чем считается модель</comment>');

            if ($loaded === []) {
                // Пустой список — это не «нет данных», а «ни одна модель
                // не держится в памяти». Значит каждый вопрос поднимает её
                // с диска заново, и первый же ответ будет очень долгим.
                $this->error('  ни одна модель не загружена в память');
                $this->line('     Каждый вопрос будет поднимать модель с диска заново.');
                $this->line('     Если модель большая, а свободной памяти мало, она может');
                $this->line('     вообще не поместиться — тогда ответа не будет никогда.');
                $this->line('     Проверьте свободную память (см. ниже) и раздел README');
                $this->line('     «Модель считается процессором».');
                $this->critical[] = 'модель не держится в памяти';
            }

            foreach ($loaded as $name => $info) {
                if (str_contains($name, (string) config('llm.embedding_model'))) {
                    continue; // модель векторов маленькая, процессора ей хватает
                }

                $gb = fn (int $bytes) => round($bytes / 1024 ** 3, 1);

                if ($info['size_vram'] > 0) {
                    $this->info(sprintf(
                        '  %s: видеокартой (%s ГБ из %s ГБ в видеопамяти)',
                        $name,
                        $gb($info['size_vram']),
                        $gb($info['size'])
                    ));
                } elseif ($info['size'] > 10 * 1024 ** 3) {
                    // Большая модель на процессоре — это не «медленновато»,
                    // а минуты на короткий вопрос. Пользоваться нельзя.
                    $this->error("  {$name}: ПРОЦЕССОРОМ, видеокарта не задействована ({$gb($info['size'])} ГБ)");
                    $this->line('     Для модели такого размера это минуты на один вопрос.');
                    $this->line('     Причина обычно одна: у контейнера ollama нет доступа к видеокарте.');
                    $this->line('     Решения — в README, раздел «Модель считается процессором».');
                    $this->critical[] = "модель «{$name}» считается процессором, а не видеокартой";
                } else {
                    // Маленькой модели процессора хватает — это рабочий вариант,
                    // например для проверки на слабой машине.
                    $this->warn("  {$name}: процессором ({$gb($info['size'])} ГБ)");
                    $this->line('     Для модели такого размера приемлемо. Крупную так запускать нельзя.');
                    $this->optional[] = "модель «{$name}» считается процессором";
                }
            }
        }

        // У LM Studio окно контекста и число параллельных слотов задаются
        // при загрузке модели в её же интерфейсе — снаружи их не узнать.
        // Напоминаем, потому что грабли там ровно те же, что у Ollama.
        if (config('llm.driver') === 'lmstudio') {
            $this->line('');
            $this->line('<comment>Проверьте в LM Studio вручную</comment>');

            // Оба драйвера читают одну переменную LLM_BASE_URL. Поменять
            // LLM_DRIVER и забыть про адрес — очень лёгкая ошибка, после
            // которой приложение продолжает говорить со старым рантаймом,
            // и понять это по поведению почти невозможно.
            if (str_contains($llm->baseUrl(), '//ollama:')) {
                $this->error('  Драйвер lmstudio, а адрес указывает на контейнер Ollama:');
                $this->error('  '.$llm->baseUrl());
                $this->line('     Поправьте LLM_BASE_URL, обычно это:');
                $this->line('     LLM_BASE_URL=http://host.docker.internal:1234/v1');
                $this->critical[] = 'выбран LM Studio, а адрес ведёт в контейнер Ollama';
            }

            $this->line('  1. Сервер включён и слушает сеть, а не только сам себя');
            $this->line('     (в LM Studio: Developer → Serve on Local Network).');
            $this->line('     Без этого приложение из контейнера до неё не достучится.');
            $this->line('  2. Context Length при загрузке модели — не меньше '
                       .((int) (config('rag.top_k') * config('rag.chunk_size') / 3) + 2500)
                       .' токенов,');
            $this->line('     иначе найденные документы обрежутся и помощник их не увидит.');
            $this->line('  3. Число параллельных запросов — 2 и больше, если сотрудников несколько.');
        }

        $contexts = $llm->contextLengths();

        if ($contexts !== []) {
            $this->line('');
            $this->line('<comment>Окно контекста</comment>');

            // Грубая оценка: сколько токенов уйдёт на найденные документы
            // плюс системное сообщение, история и сам вопрос.
            $needed = (int) (config('rag.top_k') * config('rag.chunk_size') / 3) + 2500;

            foreach ($contexts as $name => $length) {
                if (str_contains($name, (string) config('llm.embedding_model'))) {
                    continue; // модели векторов большое окно не нужно
                }

                if ($length >= $needed) {
                    $this->info("  {$name}: {$length} токенов — хватает");
                } else {
                    $this->error("  {$name}: всего {$length} токенов, а нужно около {$needed}");
                    $this->line('     Найденные документы в это окно не поместятся и будут обрезаны.');
                    $this->line('     Модель начнёт отвечать «из головы», как будто документов нет.');
                    $this->line('     Лечится переменной OLLAMA_CONTEXT_LENGTH в .env и пересозданием:');
                    $this->line('     docker compose up -d --force-recreate ollama');
                    $this->critical[] = "окно контекста мало ({$length} вместо {$needed}) — документы обрезаются";
                }
            }
        }

        if (is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
            preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $available);

            if (! empty($total[1])) {
                $this->line('');
                $this->line('<comment>Память</comment>');
                $this->line(sprintf(
                    '  системе видно %d ГБ, свободно %d ГБ',
                    (int) ($total[1] / 1024 / 1024),
                    (int) (($available[1] ?? 0) / 1024 / 1024)
                ));
                $this->line('  Если физически памяти больше, остальное отдано видеоядру.');
                $this->line('  Модель, считаемая процессором, должна поместиться именно сюда.');
            }
        }

        $this->line('');
        $this->line('<comment>Драйверы подключения к чужим базам</comment>');

        $drivers = \PDO::getAvailableDrivers();

        in_array('pgsql', $drivers, true)
            ? $this->info('  PostgreSQL: есть')
            : $this->critical[] = 'нет драйвера PostgreSQL';

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
            $this->optional[] = 'картинки помощник не видит (не указана модель со зрением)';
        } elseif (! empty($ping['models']) && $this->modelListed($vision, $ping['models'])) {
            $this->info("  зрение: модель «{$vision}» — на месте");
        } else {
            $this->warn("  зрение: модель «{$vision}» указана, но НЕ загружена. Выполните: ollama pull {$vision}");
            $this->optional[] = "модель со зрением «{$vision}» указана, но не загружена";
        }

        $stt = $speech->ping();

        if (! config('attachments.stt.enabled')) {
            $this->warn('  голос: распознавание выключено (STT_ENABLED=false)');
            $this->optional[] = 'голосовые сообщения выключены';
        } elseif ($stt['ok']) {
            $this->info('  голос: сервис распознавания отвечает ('.config('attachments.stt.model').')');
        } else {
            // Это не поломка: сервис распознавания поднимается отдельным
            // профилем и нужен только для голосовых. Всё остальное работает.
            $this->warn('  голос: сервис распознавания не запущен');
            $this->line('     Голосовые сообщения работать не будут, на остальное не влияет.');
            $this->line('     Запустить: docker compose --profile with-whisper up -d');
            $this->optional[] = 'голосовые сообщения недоступны (не запущен whisper)';
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
        $this->line('<comment>Итог</comment>');

        if ($this->critical !== []) {
            $this->error('  Помощник работать не будет. Причины:');

            foreach ($this->critical as $problem) {
                $this->line('    — '.$problem);
            }
        } else {
            $this->info('  Всё необходимое на месте, помощник готов отвечать.');
        }

        if ($this->optional !== []) {
            $this->line('');
            $this->warn('  Выключено или не настроено (на работу чата не влияет):');

            foreach ($this->optional as $problem) {
                $this->line('    — '.$problem);
            }
        }

        $this->line('');

        // Код возврата — только по критичным пунктам. Иначе make печатает
        // «Ошибка 1» из-за незапущенного распознавания речи, и оператор
        // думает, что сломалось всё.
        return $this->critical === [] ? self::SUCCESS : self::FAILURE;
    }

    /** Ollama отдаёт модели с тегом «:latest» — сравниваем без него. */
    private function modelListed(string $model, array $models): bool
    {
        $normalize = fn (string $name) => strtolower(preg_replace('/:latest$/', '', $name));

        return in_array($normalize($model), array_map($normalize, $models), true);
    }
}
