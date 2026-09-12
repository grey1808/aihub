<?php

namespace App\Agent\Tools;

use App\Models\KnowledgeSource;

/**
 * Что вообще есть у агента под рукой.
 *
 * Этот инструмент — ответ на вопрос из задачи: «модель должна знать,
 * где у неё что лежит». Список источников с описаниями агент видит
 * и в системном промпте, но отдельный инструмент позволяет ему
 * перечитать его, когда он запутался.
 */
class ListSourcesTool implements Tool
{
    public function name(): string
    {
        return 'list_knowledge_sources';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => 'Показать список подключённых источников данных: документы, Confluence, базы 1С — '
                    .'с их номерами и описанием, что в каждом лежит.',
                'parameters' => ['type' => 'object', 'properties' => (object) []],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $sources = KnowledgeSource::where('is_enabled', true)->get();

        if ($sources->isEmpty()) {
            return ['output' => 'Источники данных ещё не настроены. Обратитесь к администратору системы.'];
        }

        $lines = [];

        foreach ($sources as $source) {
            $lines[] = sprintf(
                "Источник №%d: %s (%s)\n  Документов в индексе: %d\n  Описание: %s",
                $source->id,
                $source->name,
                $source->connector()::label(),
                $source->documents_count,
                $source->description ?: 'не заполнено',
            );
        }

        return ['output' => implode("\n\n", $lines)];
    }
}
