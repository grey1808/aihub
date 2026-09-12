<?php

namespace App\Agent\Tools;

use App\Knowledge\Connectors\OneCODataConnector;
use App\Models\KnowledgeSource;

/** Живой запрос в 1С через штатный OData. */
class OneCODataTool implements Tool
{
    public function name(): string
    {
        return 'onec_odata_query';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => 'Получить данные из 1С через OData: справочники, документы, остатки регистров. '
                    .'Имена объектов и типичные запросы смотри в подсказке к источнику в системном сообщении. '
                    .'Только чтение.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'source_id' => ['type' => 'integer', 'description' => 'Номер источника 1С OData.'],
                        'entity' => [
                            'type' => 'string',
                            'description' => 'Имя объекта, например Catalog_Контрагенты или AccumulationRegister_ТоварыНаСкладах/Balance',
                        ],
                        'filter' => [
                            'type' => 'string',
                            'description' => 'Необязательно. Условие OData $filter, например: contains(Description,\'Ромашка\')',
                        ],
                        'select' => [
                            'type' => 'string',
                            'description' => 'Необязательно. Список полей через запятую, чтобы не тянуть всё подряд.',
                        ],
                    ],
                    'required' => ['source_id', 'entity'],
                ],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $source = KnowledgeSource::find((int) ($arguments['source_id'] ?? 0));

        if (! $source || $source->type !== OneCODataConnector::key()) {
            return ['output' => 'Источник с таким номером не найден или это не 1С OData.'];
        }

        if (! ($source->plainConfig()['allow_live_queries'] ?? true)) {
            return ['output' => 'Администратор запретил запросы к этому источнику во время диалога.'];
        }

        $params = [];

        if (! empty($arguments['filter'])) {
            $params['$filter'] = $arguments['filter'];
        }

        if (! empty($arguments['select'])) {
            $params['$select'] = $arguments['select'];
        }

        try {
            /** @var OneCODataConnector $connector */
            $connector = $source->connector();
            $rows = $connector->query((string) $arguments['entity'], $params);
        } catch (\Throwable $e) {
            return ['output' => '1С вернула ошибку: '.$e->getMessage()];
        }

        if ($rows === []) {
            return ['output' => 'Запрос выполнен, данных не найдено.'];
        }

        return [
            'output' => "Получено записей: ".count($rows)."\n\n"
                      .json_encode(array_slice($rows, 0, 50), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'sources' => [[
                'title'   => '1С: '.$arguments['entity'],
                'source'  => $source->name,
                'uri'     => null,
                'excerpt' => json_encode($params, JSON_UNESCAPED_UNICODE),
            ]],
        ];
    }
}
