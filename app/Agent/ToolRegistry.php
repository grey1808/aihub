<?php

namespace App\Agent;

use App\Agent\Tools\ForgetTool;
use App\Agent\Tools\KnowledgeSearchTool;
use App\Agent\Tools\ListSourcesTool;
use App\Agent\Tools\OneCODataTool;
use App\Agent\Tools\OneCSqlTool;
use App\Agent\Tools\RememberTool;
use App\Agent\Tools\Tool;
use App\Knowledge\Connectors\OneCODataConnector;
use App\Knowledge\Connectors\OneCSqlConnector;
use App\Models\KnowledgeSource;

class ToolRegistry
{
    public function __construct(
        private readonly KnowledgeSearchTool $knowledgeSearch,
        private readonly ListSourcesTool $listSources,
        private readonly OneCSqlTool $onecSql,
        private readonly OneCODataTool $onecOData,
        private readonly RememberTool $remember,
        private readonly ForgetTool $forget,
    ) {
    }

    /**
     * Набор инструментов под текущую настройку системы.
     *
     * Инструменты работы с 1С не показываем модели, если ни одной базы 1С
     * не подключено: лишние инструменты в промпте сбивают небольшие модели
     * и они начинают дёргать их без повода.
     *
     * @return array<string, Tool>
     */
    public function available(): array
    {
        $tools = [
            $this->knowledgeSearch->name() => $this->knowledgeSearch,
            $this->listSources->name()     => $this->listSources,
            $this->remember->name()        => $this->remember,
            $this->forget->name()          => $this->forget,
        ];

        if ($this->hasLive(OneCSqlConnector::key())) {
            $tools[$this->onecSql->name()] = $this->onecSql;
        }

        if ($this->hasLive(OneCODataConnector::key())) {
            $tools[$this->onecOData->name()] = $this->onecOData;
        }

        return $tools;
    }

    public function definitions(): array
    {
        return array_values(array_map(fn (Tool $tool) => $tool->definition(), $this->available()));
    }

    private function hasLive(string $type): bool
    {
        return KnowledgeSource::query()
            ->where('type', $type)
            ->where('is_enabled', true)
            ->get()
            ->contains(fn (KnowledgeSource $source) => $source->plainConfig()['allow_live_queries'] ?? true);
    }
}
