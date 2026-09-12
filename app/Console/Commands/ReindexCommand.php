<?php

namespace App\Console\Commands;

use App\Jobs\IndexDocumentJob;
use App\Models\Document;
use App\Models\KnowledgeSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReindexCommand extends Command
{
    protected $signature = 'knowledge:reindex
                            {--source= : Только один источник (его номер)}
                            {--all : Переиндексировать всё, даже уже проиндексированное}';

    protected $description = 'Заново посчитать векторы для документов базы знаний';

    public function handle(): int
    {
        $query = Document::query()
            ->when($this->option('source'), fn ($q) => $q->where('knowledge_source_id', (int) $this->option('source')))
            ->when(! $this->option('all'), fn ($q) => $q->whereIn('index_status', ['pending', 'failed']));

        $total = $query->count();

        if ($total === 0) {
            $this->info('Переиндексировать нечего.');

            return self::SUCCESS;
        }

        if ($this->option('all')) {
            $this->warn('Старые векторы будут пересчитаны. На большой базе это надолго.');

            DB::table('document_chunks')
                ->when($this->option('source'), fn ($q) => $q->where('knowledge_source_id', (int) $this->option('source')))
                ->delete();
        }

        $this->info("Ставим в очередь документов: {$total}");

        $bar = $this->output->createProgressBar($total);

        $query->select('id')->chunkById(500, function ($documents) use ($bar) {
            foreach ($documents as $document) {
                IndexDocumentJob::dispatch($document->id);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info('Готово. Задачи выполняет очередь — следите за прогрессом в админке.');

        return self::SUCCESS;
    }
}
