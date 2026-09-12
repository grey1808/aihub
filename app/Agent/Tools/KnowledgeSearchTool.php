<?php

namespace App\Agent\Tools;

use App\Models\KnowledgeSource;
use App\Rag\Retriever;
use Illuminate\Support\Str;

/** Поиск по загруженным документам компании. */
class KnowledgeSearchTool implements Tool
{
    /** @var array<int, int> источники, которыми ограничен поиск в этом проекте */
    private array $allowed = [];

    public function __construct(private readonly Retriever $retriever)
    {
    }

    /**
     * Ограничить поиск источниками проекта.
     *
     * Ставится агентом перед вызовом. Модель это ограничение обойти не может:
     * даже если она попросит искать в другом источнике, он отфильтруется.
     */
    public function restrictTo(array $sourceIds): void
    {
        $this->allowed = array_values(array_filter(array_map('intval', $sourceIds)));
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

        $sourceIds = $this->allowed;

        if (! empty($arguments['source_id'])) {
            $requested = (int) $arguments['source_id'];

            if ($this->allowed !== [] && ! in_array($requested, $this->allowed, true)) {
                return ['output' => 'Этот источник в текущем проекте искать нельзя. '
                                   .'Доступны только: '.implode(', ', $this->allowed)];
            }

            $source = KnowledgeSource::find($requested);

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
