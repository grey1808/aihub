<?php

use App\Jobs\SyncSourceJob;
use App\Models\ChatAttachment;
use App\Models\KnowledgeSource;
use Illuminate\Support\Facades\Schedule;

/*
 * Раз в пять минут смотрим, каким источникам пора обновиться.
 * Интервал у каждого источника свой — его задаёт админ в карточке.
 */
Schedule::call(function () {
    KnowledgeSource::where('is_enabled', true)->get()
        ->filter(fn (KnowledgeSource $source) => $source->isDueForSync())
        ->each(fn (KnowledgeSource $source) => SyncSourceJob::dispatch($source->id));
})->everyFiveMinutes()->name('knowledge-sources-sync')->withoutOverlapping();

/*
 * Файлы, которые загрузили и не отправили (передумал, закрыл вкладку),
 * остаются висеть на диске. Раз в час подчищаем их.
 */
Schedule::call(function () {
    ChatAttachment::query()
        ->whereNull('chat_message_id')
        ->where('created_at', '<', now()->subHours((int) config('attachments.orphan_lifetime_hours')))
        ->get()
        ->each->delete();
})->hourly()->name('attachments-cleanup')->withoutOverlapping();
