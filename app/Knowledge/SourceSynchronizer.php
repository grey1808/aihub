<?php

namespace App\Knowledge;

use App\Jobs\IndexDocumentJob;
use App\Models\Document;
use App\Models\KnowledgeSource;
use App\Models\SyncRun;
use Illuminate\Support\Facades\Log;

/**
 * Забирает документы у коннектора и кладёт их в базу.
 *
 * Переиндексируем только то, что изменилось: сравниваем хеш содержимого.
 * На источнике в 30 000 файлов это разница между «полчаса» и «двое суток».
 */
class SourceSynchronizer
{
    public function sync(KnowledgeSource $source): SyncRun
    {
        $run = $source->syncRuns()->create([
            'status'     => 'running',
            'started_at' => now(),
        ]);

        $source->update(['last_status' => 'running', 'last_error' => null]);

        $seen = [];

        try {
            $connector = $source->connector();

            foreach ($connector->documents() as $draft) {
                $run->found++;
                $seen[] = $draft->externalId;

                $existing = Document::query()
                    ->where('knowledge_source_id', $source->id)
                    ->where('external_id', $draft->externalId)
                    ->first();

                $hash = $draft->hash();

                if ($existing && $existing->content_hash === $hash && $existing->index_status === 'indexed') {
                    $run->skipped++;

                    continue;
                }

                $document = Document::updateOrCreate(
                    ['knowledge_source_id' => $source->id, 'external_id' => $draft->externalId],
                    [
                        'title'             => $draft->title,
                        'uri'               => $draft->uri,
                        'mime'              => $draft->mime,
                        'content'           => $draft->content,
                        'content_hash'      => $hash,
                        'meta'              => $draft->meta,
                        'source_updated_at' => $draft->updatedAt,
                        'index_status'      => 'pending',
                        'index_error'       => null,
                    ]
                );

                $existing ? $run->updated++ : $run->created++;

                // Векторизация — самая долгая часть, её уносим в очередь,
                // чтобы синхронизация не держала соединение с источником.
                IndexDocumentJob::dispatch($document->id);
            }

            // Что пропало на стороне источника — убираем и у нас,
            // иначе агент будет цитировать удалённые полгода назад регламенты.
            $run->deleted = Document::query()
                ->where('knowledge_source_id', $source->id)
                ->when($seen !== [], fn ($q) => $q->whereNotIn('external_id', $seen))
                ->delete();

            $run->status = 'ok';
            $run->finished_at = now();
            $run->save();

            $source->update([
                'last_status'     => 'ok',
                'last_synced_at'  => now(),
                'last_error'      => null,
                'documents_count' => $source->documents()->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Синхронизация источника упала', [
                'source' => $source->id,
                'error'  => $e->getMessage(),
            ]);

            $run->status = 'error';
            $run->message = $e->getMessage();
            $run->finished_at = now();
            $run->save();

            $source->update(['last_status' => 'error', 'last_error' => $e->getMessage()]);
        }

        return $run;
    }
}
