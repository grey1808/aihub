<?php

namespace App\Jobs;

use App\Models\Document;
use App\Rag\Indexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IndexDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 900;

    public function __construct(public int $documentId)
    {
        $this->onQueue('index');
    }

    public function handle(Indexer $indexer): void
    {
        $document = Document::find($this->documentId);

        if (! $document) {
            return;
        }

        $indexer->index($document);
    }

    public function failed(\Throwable $e): void
    {
        Document::where('id', $this->documentId)->update([
            'index_status' => 'failed',
            'index_error'  => $e->getMessage(),
        ]);
    }
}
