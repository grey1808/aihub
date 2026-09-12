<?php

namespace App\Rag;

use App\Llm\LlmClient;
use Illuminate\Support\Facades\DB;

/**
 * Поиск по базе знаний.
 *
 * Гибридный: вектора ловят смысл ("как оформить отпуск" найдёт "порядок
 * предоставления ежегодного оплачиваемого отпуска"), полнотекст ловит точные
 * строки — номера договоров, артикулы, фамилии. По отдельности каждый способ
 * регулярно промахивается, вместе работают заметно лучше.
 */
class Retriever
{
    public function __construct(private readonly LlmClient $llm)
    {
    }

    /**
     * @return array<int, array{content:string,title:string,uri:?string,source:string,score:float,document_id:int}>
     */
    public function search(string $query, ?int $limit = null, array $sourceIds = []): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $limit = $limit ?: (int) config('rag.top_k');

        $vector = $this->llm->embed([$query])[0] ?? null;

        if (! $vector) {
            return [];
        }

        $literal = '['.implode(',', array_map(fn ($v) => (float) $v, $vector)).']';

        $filter = $sourceIds ? 'AND c.knowledge_source_id IN ('.implode(',', array_map('intval', $sourceIds)).')' : '';

        // Векторная половина: 1 - косинусное расстояние = похожесть 0..1.
        $semantic = DB::select("
            SELECT c.id, c.content, c.document_id, 1 - (c.embedding <=> ?::vector) AS score
            FROM document_chunks c
            WHERE c.embedding IS NOT NULL {$filter}
            ORDER BY c.embedding <=> ?::vector
            LIMIT ?
        ", [$literal, $literal, $limit * 3]);

        // Полнотекстовая половина. Ошибки websearch_to_tsquery на кривом
        // вводе не должны ронять весь поиск — вектора всё равно отработали.
        try {
            $keyword = DB::select("
                SELECT c.id, c.content, c.document_id,
                       ts_rank(to_tsvector('russian', c.content), websearch_to_tsquery('russian', ?)) AS score
                FROM document_chunks c
                WHERE to_tsvector('russian', c.content) @@ websearch_to_tsquery('russian', ?) {$filter}
                ORDER BY score DESC
                LIMIT ?
            ", [$query, $query, $limit * 2]);
        } catch (\Throwable) {
            $keyword = [];
        }

        $merged = $this->fuse($semantic, $keyword, $limit);

        return $this->decorate($merged);
    }

    /**
     * Объединение двух списков по формуле Reciprocal Rank Fusion:
     * важно не абсолютное значение score (у них разные шкалы),
     * а на каком месте кусок оказался в каждом списке.
     */
    private function fuse(array $semantic, array $keyword, int $limit): array
    {
        $scores = [];
        $chunks = [];
        $k = 60;

        foreach ([$semantic, $keyword] as $list) {
            foreach (array_values($list) as $rank => $row) {
                $scores[$row->id] = ($scores[$row->id] ?? 0) + 1 / ($k + $rank + 1);
                $chunks[$row->id] = $row;
            }
        }

        // Совсем непохожие куски отбрасываем: пустой контекст лучше,
        // чем случайный — на случайном модель начинает сочинять.
        $minScore = (float) config('rag.min_score');

        foreach ($semantic as $row) {
            if ($row->score < $minScore && ! $this->inList($keyword, $row->id)) {
                unset($scores[$row->id], $chunks[$row->id]);
            }
        }

        arsort($scores);

        $result = [];

        foreach (array_slice(array_keys($scores), 0, $limit) as $id) {
            $result[] = ['chunk' => $chunks[$id], 'score' => $scores[$id]];
        }

        return $result;
    }

    private function inList(array $rows, int $id): bool
    {
        foreach ($rows as $row) {
            if ($row->id === $id) {
                return true;
            }
        }

        return false;
    }

    /** Дотягиваем названия документов и источников — их показываем пользователю. */
    private function decorate(array $merged): array
    {
        if ($merged === []) {
            return [];
        }

        $documentIds = array_unique(array_map(fn ($r) => $r['chunk']->document_id, $merged));

        $documents = DB::table('documents')
            ->join('knowledge_sources', 'knowledge_sources.id', '=', 'documents.knowledge_source_id')
            ->whereIn('documents.id', $documentIds)
            ->get([
                'documents.id', 'documents.title', 'documents.uri',
                'knowledge_sources.name as source_name',
            ])
            ->keyBy('id');

        $out = [];

        foreach ($merged as $row) {
            $document = $documents->get($row['chunk']->document_id);

            if (! $document) {
                continue;
            }

            $out[] = [
                'document_id' => $document->id,
                'title'       => $document->title,
                'uri'         => $document->uri,
                'source'      => $document->source_name,
                'content'     => $row['chunk']->content,
                'score'       => round($row['score'], 4),
            ];
        }

        return $out;
    }
}
