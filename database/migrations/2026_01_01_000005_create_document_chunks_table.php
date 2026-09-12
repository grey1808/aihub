<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // pgvector — расширение Postgres, которое умеет хранить векторы
        // и быстро искать ближайшие. Образ pgvector/pgvector его уже содержит.
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        $dimensions = (int) config('llm.embedding_dimensions');

        DB::statement("
            CREATE TABLE document_chunks (
                id                  bigserial PRIMARY KEY,
                document_id         bigint NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
                knowledge_source_id bigint NOT NULL REFERENCES knowledge_sources(id) ON DELETE CASCADE,
                chunk_index         integer NOT NULL,
                content             text NOT NULL,
                embedding           vector({$dimensions}),
                created_at          timestamp NULL,
                updated_at          timestamp NULL
            )
        ");

        DB::statement('CREATE INDEX document_chunks_document_id_index ON document_chunks (document_id)');
        DB::statement('CREATE INDEX document_chunks_source_id_index ON document_chunks (knowledge_source_id)');

        // HNSW — индекс приближённого поиска ближайших векторов.
        // Без него поиск по большой базе будет перебирать всё подряд.
        DB::statement('CREATE INDEX document_chunks_embedding_index ON document_chunks
                       USING hnsw (embedding vector_cosine_ops)');

        // Полнотекстовый поиск по-русски — вторая половина гибридного поиска.
        // Векторы хорошо ловят смысл, полнотекст — точные номера, коды, артикулы.
        DB::statement("CREATE INDEX document_chunks_fts_index ON document_chunks
                       USING gin (to_tsvector('russian', content))");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
