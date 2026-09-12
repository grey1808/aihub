<?php

namespace App\Agent\Tools;

use App\Models\KnowledgeSource;
use App\Rag\Retriever;
use Illuminate\Support\Str;

/** Поиск по загруженным документам компании. */
class KnowledgeSearchTool implements Tool
{
    public function __construct(private readonly Retriever $retriever)
    {
    }

    public function name(): string
    {
        return 'knowledge_search';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => 'Поиск по документам компании: регламенты, инструкции, приказы, страницы Confluence, '
                    .'файлы из общих папок, выгрузки из 1С. Используй всегда, когда вопрос касается внутренних документов '
                    .'или порядков компании. Можно вызывать несколько раз с разными формулировками.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Поисковый запрос словами пользователя или переформулированный. Русский язык.',
                        ],
                        'source_id' => [
                            'type' => 'integer',
                            'description' => 'Необязательно. Искать только в одном источнике — его номер из списка источников.',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));

        if ($query === '') {
            return ['output' => 'Пустой поисковый запрос.'];
        }

        $sourceIds = [];

        if (! empty($arguments['source_id'])) {
            $source = KnowledgeSource::find((int) $arguments['source_id']);

            if ($source) {
                $sourceIds = [$source->id];
            }
        }

        $results = $this->retriever->search($query, null, $sourceIds);

        if ($results === []) {
            return ['output' => 'По запросу «'.$query.'» в базе знаний ничего не найдено.'];
        }

        $blocks = [];

        foreach ($results as $i => $row) {
            $number = $i + 1;
            $blocks[] = "[{$number}] Документ: {$row['title']} (источник: {$row['source']})\n{$row['content']}";
        }

        return [
            'output'  => "Найдено фрагментов: ".count($results)."\n\n".implode("\n\n---\n\n", $blocks),
            'sources' => array_map(fn ($row) => [
                'title'  => $row['title'],
                'source' => $row['source'],
                'uri'    => $row['uri'],
                'score'  => $row['score'],
                'excerpt' => Str::limit($row['content'], 300),
            ], $results),
        ];
    }
}
