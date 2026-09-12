<?php

namespace App\Agent;

use App\Knowledge\Connectors\OneCODataConnector;
use App\Knowledge\Connectors\OneCSqlConnector;
use App\Models\KnowledgeSource;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;

/**
 * Собирает системное сообщение.
 *
 * Главная мысль задачи: пользователь не должен объяснять агенту, где
 * что лежит и как этим пользоваться. Значит, эту карту агент получает
 * заранее — из описаний источников, которые заполнил админ.
 */
class PromptBuilder
{
    public const DEFAULT_PROMPT = <<<'TXT'
Ты — корпоративный помощник компании. Отвечаешь сотрудникам на русском языке.

Как работать:
1. Если вопрос касается внутренних документов, регламентов, порядков или данных компании — сначала найди информацию через доступные инструменты, потом отвечай. Не выдумывай.
2. Если инструменты ничего не нашли — так и скажи: «в базе знаний по этому вопросу ничего нет». Не подменяй факты общими рассуждениями.
3. Всегда указывай, из какого документа взят ответ.
4. Отвечай по делу и коротко. Сотруднику нужен ответ, а не пересказ инструкции.
5. Если вопрос неоднозначный, уточни, но только один раз и по существу.
6. Общие вопросы, не связанные с компанией, отвечай сам, без поиска.
7. Учитывай должность сотрудника: одному и тому же вопросу от бухгалтера и от кладовщика нужны разные ответы.
8. Если сотрудник просит что-то запомнить на будущее, сохрани это инструментом remember. Если просит забыть — forget.
TXT;

    public function build(?User $user = null, ?Project $project = null): string
    {
        $parts = [Setting::get('system_prompt', self::DEFAULT_PROMPT)];

        $company = Setting::get('company_name');

        if ($company) {
            $parts[] = "Название компании: {$company}.";
        }

        if ($user) {
            $parts[] = $this->userCard($user);
        }

        $parts[] = 'Сегодня '.now()->translatedFormat('j F Y, l').'.';

        $catalog = $this->sourceCatalog($project);

        if ($catalog !== '') {
            $parts[] = $catalog;
        }

        if ($project) {
            $parts[] = $this->projectCard($project);
        }

        // Личные заметки идут последними — так они ближе всего к вопросу
        // и модель обращает на них больше внимания.
        if ($user && trim((string) $user->memory) !== '') {
            $parts[] = $this->memoryCard($user);
        }

        return implode("\n\n", $parts);
    }

    /** Кто задаёт вопрос. */
    private function userCard(User $user): string
    {
        $lines = ['С ТОБОЙ РАЗГОВАРИВАЕТ:'];
        $lines[] = 'Имя: '.$user->callName();

        if ($user->position) {
            $lines[] = 'Должность: '.$user->position;
        }

        if ($user->department) {
            $lines[] = 'Отдел: '.$user->department;
        }

        $lines[] = 'Обращайся к сотруднику по имени и учитывай его должность, подбирая ответ.';

        return implode("\n", $lines);
    }

    /**
     * Личная память помощника об этом сотруднике.
     *
     * Это и есть «agent.md на каждого пользователя»: заметки, которые
     * помощник читает перед каждым ответом — без всяких инструментов,
     * просто потому что они уже здесь.
     */
    private function memoryCard(User $user): string
    {
        return "ТВОИ ЗАМЕТКИ ОБ ЭТОМ СОТРУДНИКЕ (личная память, её видит только он):\n"
             .trim((string) $user->memory)."\n\n"
             ."Учитывай эти заметки в каждом ответе. Если сотрудник просит что-то запомнить "
             ."или изменить в заметках — используй инструменты remember и forget.";
    }

    /**
     * Проект, в котором идёт разговор.
     *
     * Инструкция и заметки проекта общие для всех его чатов — это и есть
     * то, ради чего проекты нужны: не пересказывать контекст заново
     * в каждом новом чате по одной и той же теме.
     */
    private function projectCard(Project $project): string
    {
        $lines = ["РАЗГОВОР ИДЁТ В ПРОЕКТЕ «{$project->name}»."];

        if ($project->description) {
            $lines[] = 'О чём проект: '.trim($project->description);
        }

        if ($project->instructions) {
            $lines[] = "Инструкция для этого проекта — следуй ей во всех ответах здесь:\n".trim($project->instructions);
        }

        if (trim((string) $project->notes) !== '') {
            $lines[] = "Накопленные заметки по проекту (общие для всех его чатов):\n".trim($project->notes)
                     ."\n\nЕсли по ходу разговора выяснится что-то важное для всего проекта, "
                     ."сохрани это инструментом remember со scope = project.";
        }

        return implode("\n\n", $lines);
    }

    /** Карта источников: что подключено, что внутри, как этим пользоваться. */
    private function sourceCatalog(?Project $project = null): string
    {
        $sources = $project && $project->sourceIds() !== []
            ? KnowledgeSource::whereIn('id', $project->sourceIds())->where('is_enabled', true)->orderBy('id')->get()
            : KnowledgeSource::where('is_enabled', true)->orderBy('id')->get();

        if ($sources->isEmpty()) {
            return 'Источники данных пока не настроены — база знаний пуста. '
                 .'Если у сотрудника вопрос по документам компании, честно скажи, что база знаний ещё не заполнена.';
        }

        $lines = [$project && $project->sourceIds() !== []
            ? 'ИСТОЧНИКИ ДАННЫХ, РАЗРЕШЁННЫЕ В ЭТОМ ПРОЕКТЕ (искать только в них):'
            : 'ПОДКЛЮЧЁННЫЕ ИСТОЧНИКИ ДАННЫХ:'];

        foreach ($sources as $source) {
            $config = $source->plainConfig();

            $line = "\n№{$source->id}. {$source->name} — ".$source->connector()::label();

            if ($source->description) {
                $line .= "\n   Что здесь лежит: {$source->description}";
            }

            if ($source->type === OneCSqlConnector::key() && ! empty($config['schema_hint'])) {
                $line .= "\n   Описание таблиц (используй для onec_sql_query, источник №{$source->id}):\n"
                       .$this->indent($config['schema_hint']);
            }

            if ($source->type === OneCODataConnector::key() && ! empty($config['usage_hint'])) {
                $line .= "\n   Как запрашивать (onec_odata_query, источник №{$source->id}):\n"
                       .$this->indent($config['usage_hint']);
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    private function indent(string $text): string
    {
        return collect(explode("\n", trim($text)))
            ->map(fn (string $line) => '     '.$line)
            ->implode("\n");
    }
}
