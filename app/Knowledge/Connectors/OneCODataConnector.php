<?php

namespace App\Knowledge\Connectors;

use App\Knowledge\Contracts\Connector;
use App\Knowledge\DocumentDraft;
use App\Models\KnowledgeSource;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * 1С через OData (интерфейс REST, встроенный в платформу).
 *
 * Предпочтительнее прямого SQL: имена объектов человеческие
 * (Catalog_Контрагенты вместо _Reference123), права уважаются штатные,
 * и не нужен доступ к самой СУБД. Включается в 1С:
 * Администрирование → Публикация на веб-сервере → «Публиковать стандартный интерфейс OData».
 */
class OneCODataConnector implements Connector
{
    public function __construct(private readonly KnowledgeSource $source)
    {
    }

    public static function key(): string
    {
        return 'onec_odata';
    }

    public static function label(): string
    {
        return '1С через OData (REST-интерфейс)';
    }

    public static function help(): string
    {
        return 'Штатный веб-интерфейс 1С. Удобнее прямого SQL: понятные имена объектов и штатные права доступа.';
    }

    public static function fields(): array
    {
        return [
            ['name' => 'base_url', 'label' => 'Адрес OData', 'type' => 'text', 'required' => true,
             'placeholder' => 'http://1c-server/base_name/odata/standard.odata',
             'help' => 'Адрес публикации базы плюс /odata/standard.odata'],

            ['name' => 'username', 'label' => 'Пользователь 1С', 'type' => 'text', 'required' => true],
            ['name' => 'password', 'label' => 'Пароль', 'type' => 'password', 'required' => true],

            ['name' => 'entities', 'label' => 'Что индексировать', 'type' => 'textarea', 'rows' => 6,
             'placeholder' => "Catalog_Контрагенты\nCatalog_Номенклатура\nDocument_СчетНаОплатуПокупателю",
             'help' => 'Имена объектов OData, по одному в строке. Пусто — ничего не индексируем, '
                      .'источник работает только как справочник для агента.'],

            ['name' => 'allow_live_queries', 'label' => 'Разрешить агенту запрашивать данные во время диалога',
             'type' => 'checkbox', 'default' => true],

            ['name' => 'usage_hint', 'label' => 'Подсказка агенту', 'type' => 'textarea', 'rows' => 8,
             'placeholder' => "Остатки смотреть в AccumulationRegister_ТоварыНаСкладах/Balance\n"
                             ."Контрагента искать: Catalog_Контрагенты?\$filter=contains(Description,'ромашка')",
             'help' => 'Опишите словами, что где лежит и какие запросы типичны. Агент прочитает это перед работой.'],

            ['name' => 'max_rows', 'label' => 'Максимум записей в ответе', 'type' => 'number', 'default' => 100],
        ];
    }

    public function test(): array
    {
        $response = $this->http()->get('/', ['$format' => 'json']);

        if ($response->status() === 401) {
            return ['ok' => false, 'message' => '1С не приняла логин или пароль.'];
        }

        if ($response->failed()) {
            return ['ok' => false, 'message' => '1С ответила ошибкой '.$response->status().'. Проверьте адрес публикации и что OData включён.'];
        }

        $count = count($response->json('value') ?? []);

        return ['ok' => true, 'message' => "Подключение есть, объектов доступно: {$count}"];
    }

    public function documents(): iterable
    {
        $entities = array_filter(array_map('trim', explode("\n", (string) ($this->source->plainConfig()['entities'] ?? ''))));

        foreach ($entities as $entity) {
            $skip = 0;
            $top = 200;

            do {
                $response = $this->http()->get('/'.$entity, [
                    '$format' => 'json',
                    '$top'    => $top,
                    '$skip'   => $skip,
                ]);

                if ($response->failed()) {
                    throw new \RuntimeException("Объект {$entity}: 1С ответила ошибкой ".$response->status());
                }

                $rows = $response->json('value') ?? [];

                foreach ($rows as $row) {
                    $lines = [];

                    foreach ($row as $field => $value) {
                        if (is_scalar($value) && $value !== '' && ! str_starts_with($field, '@')) {
                            $lines[] = $field.': '.$value;
                        }
                    }

                    if ($lines === []) {
                        continue;
                    }

                    $key = $row['Ref_Key'] ?? md5(json_encode($row));
                    $title = $row['Description'] ?? $row['Number'] ?? $key;

                    yield new DocumentDraft(
                        externalId: $entity.':'.$key,
                        title: $entity.' — '.$title,
                        content: "Объект 1С: {$entity}\n\n".implode("\n", $lines),
                        mime: 'application/x-1c-odata',
                        meta: ['entity' => $entity, 'ref_key' => $key],
                    );
                }

                $skip += $top;
            } while (count($rows) === $top);
        }
    }

    /** Запрос от имени агента во время диалога. */
    public function query(string $entity, array $params = []): array
    {
        $max = (int) ($this->source->plainConfig()['max_rows'] ?? 100);

        $response = $this->http()->get('/'.ltrim($entity, '/'), array_merge([
            '$format' => 'json',
            '$top'    => $max,
        ], $params));

        if ($response->failed()) {
            throw new \RuntimeException('1С ответила ошибкой '.$response->status().': '.\Illuminate\Support\Str::limit($response->body(), 300));
        }

        return $response->json('value') ?? $response->json() ?? [];
    }

    private function http(): PendingRequest
    {
        $config = $this->source->plainConfig();

        return Http::baseUrl(rtrim((string) ($config['base_url'] ?? ''), '/'))
            ->withBasicAuth($config['username'] ?? '', $config['password'] ?? '')
            ->acceptJson()
            ->timeout(120);
    }
}
