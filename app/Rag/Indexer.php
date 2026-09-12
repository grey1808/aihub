<?php

namespace App\Rag;

use App\Llm\LlmClient;
use App\Models\Document;
use Illuminate\Support\Facades\DB;

/** Превращает документ в набор кусков с векторами. */
class Indexer
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly Chunker $chunker,
    ) {
    }

    public function index(Document $document): int
    {
        $chunks = $this->chunker->split((string) $document->content);

        if ($chunks === []) {
            $document->update(['index_status' => 'indexed']);

            return 0;
        }

        // Заголовок документа приклеиваем к каждому куску. Иначе кусок
        // из середины файла теряет контекст, и поиск по названию документа
        // его не находит.
        $prefixed = array_map(
            fn (string $chunk) => $document->title."\n\n".$chunk,
            $chunks
        );

        $vectors = [];

        // Векторизуем пачками: один запрос на 16 кусков вместо 16 запросов.
        foreach (array_chunk($prefixed, 16, true) as $batch) {
            foreach ($this->llm->embed(array_values($batch)) as $i => $vector) {
                $vectors[array_keys($batch)[$i]] = $vector;
            }
        }

        DB::transaction(function () use ($document, $chunks, $vectors) {
            DB::table('document_chunks')->where('document_id', $document->id)->delete();

            $now = now();

            foreach ($chunks as $index => $chunk) {
                if (! isset($vectors[$index])) {
                    continue;
                }

                // Тип vector не умеет биндиться штатно, поэтому приводим
                // литерал явным cast'ом — значение всё равно идёт параметром.
                DB::insert(
                    'INSERT INTO document_chunks
                        (document_id, knowledge_source_id, chunk_index, content, embedding, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?::vector, ?, ?)',
                    [
                        $document->id,
                        $document->knowledge_source_id,
                        $index,
                        $chunk,
                        '['.implode(',', array_map(fn ($v) => (float) $v, $vectors[$index])).']',
                        $now,
                        $now,
                    ]
                );
            }

            $document->update(['index_status' => 'indexed', 'index_error' => null]);
        });

        return count($chunks);
    }
}
