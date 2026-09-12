<?php

namespace App\Knowledge\Connectors;

use App\Knowledge\Contracts\Connector;
use App\Knowledge\DocumentDraft;
use App\Knowledge\Sql\DynamicConnection;
use App\Knowledge\Sql\SqlGuard;
use App\Models\KnowledgeSource;

/**
 * База данных 1С (или любая другая SQL-база) на чтение.
 *
 * Работает в двух режимах одновременно:
 *
 *  1. Индексация. Админ пишет именованные запросы — их результат
 *     превращается в документы и попадает в базу знаний. Так в поиск
 *     попадают справочники: контрагенты, номенклатура, статьи затрат.
 *
 *  2. Живые запросы. Агент во время диалога может сам выполнить SELECT
 *     через инструмент onec_sql_query, когда у пользователя вопрос про
 *     актуальные цифры ("остаток на складе сейчас"), а не про документ.
 *     Для этого он читает "Описание таблиц" — карту, которую заполнил админ.
 *
 * Прямые имена таблиц 1С нечитаемы (_Document123_VT456), поэтому поле
 * с описанием схемы обязательно: без него модель не угадает, где что лежит.
 */
class OneCSqlConnector implements Connector
{
    public function __construct(private readonly KnowledgeSource $source)
    {
    }

    public static function key(): string
    {
        return 'onec_sql';
    }

    public static function label(): string
    {
        return 'База данных 1С (прямой SQL, только чтение)';
    }

    public static function help(): string
    {
        return 'Прямое подключение к СУБД, на которой работает 1С. Агент сможет доставать данные запросами SELECT. '
             .'Обязательно заведите отдельного пользователя СУБД с правами только на чтение.';
    }

    public static function fields(): array
    {
        return [
            ['name' => 'driver', 'label' => 'СУБД', 'type' => 'select', 'required' => true, 'default' => 'sqlsrv',
             'options' => ['sqlsrv' => 'Microsoft SQL Server', 'pgsql' => 'PostgreSQL']],

            ['name' => 'host', 'label' => 'Сервер', 'type' => 'text', 'required' => true,
             'placeholder' => '192.168.1.20'],

            ['name' => 'port', 'label' => 'Порт', 'type' => 'number',
             'placeholder' => '1433 для MS SQL, 5432 для PostgreSQL'],

            ['name' => 'database', 'label' => 'Имя базы', 'type' => 'text', 'required' => true,
             'placeholder' => 'buh_accounting'],

            ['name' => 'username', 'label' => 'Пользователь', 'type' => 'text', 'required' => true,
             'help' => 'Учётка только на чтение (db_datareader / SELECT).'],

            ['name' => 'password', 'label' => 'Пароль', 'type' => 'password', 'required' => true],

            ['name' => 'schema_hint', 'label' => 'Описание таблиц для агента', 'type' => 'textarea',
             'rows' => 12,
             'placeholder' => "Справочник «Контрагенты» — таблица _Reference123:\n"
                             ."  _IDRRef — идентификатор\n"
                             ."  _Description — наименование\n"
                             ."  _Fld456 — ИНН\n\n"
                             ."Остатки на складе — представление dbo.v_Ostatki (Номенклатура, Склад, Количество)",
             'help' => 'Самое важное поле. Опишите словами, какие таблицы и представления есть и что в них лежит. '
                      .'Без этого агент не разберётся в именах вида _Document123_VT456. '
                      .'Лучший вариант — попросить программиста 1С сделать несколько SQL-представлений с понятными именами и описать их здесь.'],

            ['name' => 'allow_live_queries', 'label' => 'Разрешить агенту выполнять запросы во время диалога',
             'type' => 'checkbox', 'default' => true,
             'help' => 'Если выключить, источник будет использоваться только для индексации по расписанию.'],

            ['name' => 'max_rows', 'label' => 'Максимум строк в ответе', 'type' => 'number', 'default' => 200,
             'help' => 'Защита от запроса, который вытащит миллион строк и переполнит контекст модели.'],

            ['name' => 'index_queries', 'label' => 'Запросы для индексации', 'type' => 'textarea',
             'rows' => 10,
             'placeholder' => "# Контрагенты\nSELECT _Description AS naimenovanie, _Fld456 AS inn FROM _Reference123\n\n"
                             ."# Номенклатура\nSELECT _Description AS tovar, _Fld789 AS artikul FROM _Reference456",
             'help' => 'Необязательно. Каждый блок: строка «# Название», затем один SELECT. '
                      .'Каждая строка результата станет документом в базе знаний.'],
        ];
    }

    public function test(): array
    {
        try {
            $connection = DynamicConnection::for($this->source);
            $connection->select('SELECT 1 AS ok');

            return ['ok' => true, 'message' => 'Подключение к базе есть.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Не подключились: '.$e->getMessage()];
        }
    }

    public function documents(): iterable
    {
        $connection = DynamicConnection::for($this->source);

        foreach ($this->indexQueries() as $name => $sql) {
            SqlGuard::assertReadOnly($sql);

            $rows = $connection->select($sql);

            foreach ($rows as $index => $row) {
                $row = (array) $row;

                // Строку таблицы превращаем в текст "поле: значение",
                // иначе эмбеддинг от сырого JSON получается бессмысленным.
                $lines = [];
                foreach ($row as $column => $value) {
                    if ($value !== null && $value !== '') {
                        $lines[] = $column.': '.trim((string) $value);
                    }
                }

                if ($lines === []) {
                    continue;
                }

                yield new DocumentDraft(
                    externalId: 'query:'.md5($name).':'.$index,
                    title: $name.' — '.\Illuminate\Support\Str::limit(trim((string) reset($row)), 80),
                    content: "Данные из 1С, выборка «{$name}»\n\n".implode("\n", $lines),
                    uri: null,
                    mime: 'application/x-1c-row',
                    meta: ['query' => $name, 'row' => $row],
                );
            }
        }
    }

    /** Запросы для индексации: "# Название" + SELECT под ним. */
    private function indexQueries(): array
    {
        $raw = trim((string) ($this->source->plainConfig()['index_queries'] ?? ''));

        if ($raw === '') {
            return [];
        }

        $queries = [];
        $name = 'Выборка';
        $buffer = [];

        foreach (explode("\n", $raw) as $line) {
            if (str_starts_with(trim($line), '#')) {
                if (trim(implode("\n", $buffer)) !== '') {
                    $queries[$name] = trim(implode("\n", $buffer));
                }

                $name = trim(ltrim(trim($line), '# '));
                $buffer = [];

                continue;
            }

            $buffer[] = $line;
        }

        if (trim(implode("\n", $buffer)) !== '') {
            $queries[$name] = trim(implode("\n", $buffer));
        }

        return $queries;
    }
}
