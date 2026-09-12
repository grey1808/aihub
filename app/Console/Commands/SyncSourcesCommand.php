<?php

namespace App\Console\Commands;

use App\Jobs\SyncSourceJob;
use App\Models\KnowledgeSource;
use Illuminate\Console\Command;

class SyncSourcesCommand extends Command
{
    protected $signature = 'knowledge:sync {--source= : Только один источник (его номер)}';

    protected $description = 'Забрать документы из источников знаний прямо сейчас';

    public function handle(): int
    {
        $sources = KnowledgeSource::query()
            ->where('is_enabled', true)
            ->when($this->option('source'), fn ($q) => $q->whereKey((int) $this->option('source')))
            ->get();

        if ($sources->isEmpty()) {
            $this->warn('Подходящих включённых источников нет.');

            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            SyncSourceJob::dispatch($source->id);
            $this->line("В очередь: №{$source->id} {$source->name}");
        }

        return self::SUCCESS;
    }
}
