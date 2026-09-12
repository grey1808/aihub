<?php

namespace App\Jobs;

use App\Knowledge\SourceSynchronizer;
use App\Models\KnowledgeSource;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncSourceJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 7200;

    public function __construct(public int $sourceId)
    {
        $this->onQueue('sync');
    }

    /** Один источник не синхронизируем двумя задачами одновременно. */
    public function uniqueId(): string
    {
        return 'sync-source-'.$this->sourceId;
    }

    public function uniqueFor(): int
    {
        return 7200;
    }

    public function handle(SourceSynchronizer $synchronizer): void
    {
        $source = KnowledgeSource::find($this->sourceId);

        if ($source && $source->is_enabled) {
            $synchronizer->sync($source);
        }
    }
}
