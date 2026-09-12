<?php

use App\Jobs\SyncSourceJob;
use App\Models\ChatAttachment;
use App\Models\ChatThread;
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

/*
 * Чистим корзину: чаты, удалённые больше месяца назад, убираем совсем.
 * Вместе с ними уходят сообщения и приложенные файлы.
 */
Schedule::call(function () {
    $days = (int) config('chat.trash_lifetime_days');

    if ($days <= 0) {
        return;
    }

    ChatThread::onlyTrashed()
        ->where('deleted_at', '<', now()->subDays($days))
        ->get()
        ->each->forceDelete();
})->dailyAt('03:30')->name('chat-trash-cleanup')->withoutOverlapping();
