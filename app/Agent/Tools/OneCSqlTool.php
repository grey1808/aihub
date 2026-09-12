<?php

namespace App\Agent\Tools;

use App\Knowledge\Connectors\OneCSqlConnector;
use App\Knowledge\Sql\DynamicConnection;
use App\Knowledge\Sql\SqlGuard;
use App\Models\KnowledgeSource;
use Illuminate\Support\Facades\Log;

/**
 * Живой запрос в базу 1С.
 *
 * Нужен там, где база знаний бессильна: остатки на сегодня, сумма
 * по контрагенту за квартал, список неоплаченных счетов. Такие цифры
 * нельзя проиндексировать заранее — они меняются каждую минуту.
 *
 * Только чтение. Любой SELECT проходит через SqlGuard, а пользователь
 * СУБД для этого подключения должен иметь права только на чтение.
 */
class OneCSqlTool implements Tool
{
    public function name(): string
    {
        return 'onec_sql_query';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => 'Выполнить запрос SELECT к базе данных 1С и получить актуальные цифры: остатки, '
                    .'суммы, списки документов. Перед первым запросом обязательно посмотри описание таблиц '
                    .'в системном сообщении — имена таблиц в 1С нечитаемые, угадывать их бесполезно. '
                    .'Разрешено только чтение, любые изменения данных запрещены.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'source_id' => [
                            'type' => 'integer',
                            'description' => 'Номер источника с базой 1С из списка источников.',
                        ],
                        'sql' => [
                            'type' => 'string',
                            'description' => 'Один запрос SELECT. Без точки с запятой в конце.',
                        ],
                        'purpose' => [
                            'type' => 'string',
                            'description' => 'Одной фразой: что именно ты хочешь узнать этим запросом.',
                        ],
                    ],
                    'required' => ['source_id', 'sql'],
                ],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $source = KnowledgeSource::find((int) ($arguments['source_id'] ?? 0));

        if (! $source || $source->type !== OneCSqlConnector::key()) {
            return ['output' => 'Источник с таким номером не найден или это не база 1С. '
                               .'Посмотри список через list_knowledge_sources.'];
        }

        $config = $source->plainConfig();

        if (! ($config['allow_live_queries'] ?? true)) {
            return ['output' => 'Администратор запретил выполнять запросы к этому источнику во время диалога.'];
        }

        $sql = (string) ($arguments['sql'] ?? '');

        try {
            SqlGuard::assertReadOnly($sql);
        } catch (\InvalidArgumentException $e) {
            return ['output' => 'Запрос не выполнен. '.$e->getMessage()];
        }

        $limit = (int) ($config['max_rows'] ?? 200);
        $sql = SqlGuard::withLimit($sql, $limit, $config['driver'] ?? 'pgsql');

        try {
            $connection = DynamicConnection::for($source);
            $rows = $connection->select($sql);
        } catch (\Throwable $e) {
            Log::warning('Запрос агента к 1С не выполнился', ['sql' => $sql, 'error' => $e->getMessage()]);

            return ['output' => 'База вернула ошибку: '.$e->getMessage()
                               ."\nПроверь имена таблиц и полей по описанию схемы и попробуй иначе."];
        }

        if ($rows === []) {
            return ['output' => 'Запрос выполнен, но не вернул ни одной строки.'];
        }

        return [
            'output' => "Запрос выполнен, строк: ".count($rows)."\n\n".$this->asTable($rows),
            'sources' => [[
                'title'   => 'Запрос к 1С: '.($arguments['purpose'] ?? 'данные из базы'),
                'source'  => $source->name,
                'uri'     => null,
                'excerpt' => $sql,
            ]],
        ];
    }

    /** Результат отдаём таблицей — так модель её точно не перепутает по столбцам. */
    private function asTable(array $rows): string
    {
        $rows = array_map(fn ($row) => (array) $row, $rows);
        $columns = array_keys($rows[0]);

        $lines = [implode(' | ', $columns), implode(' | ', array_fill(0, count($columns), '---'))];

        foreach ($rows as $row) {
            $lines[] = implode(' | ', array_map(
                fn ($value) => $value === null ? '' : trim((string) $value),
                $row
            ));
        }

        return implode("\n", $lines);
    }
}
