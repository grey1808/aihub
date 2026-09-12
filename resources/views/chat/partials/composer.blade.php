{{-- Поле ввода с вложениями.
     pb-[env(safe-area-inset-bottom)] — чтобы на iPhone кнопки
     не оказались под полосой жестов. --}}
<div class="shrink-0 border-t border-gray-200 bg-white pb-[env(safe-area-inset-bottom)]">
    <form id="send-form"
          method="POST"
          action="{{ route('chat.send', $thread) }}"
          data-upload-url="{{ route('attachments.store') }}"
          data-thread="{{ $thread->id }}"
          data-max-files="{{ $limits['maxFiles'] }}"
          data-max-size="{{ $limits['maxSize'] }}"
          class="p-2 sm:p-3">
        @csrf

        {{-- Приложенные файлы до отправки --}}
        <div id="attachment-list" class="mb-2 hidden flex-wrap gap-2"></div>

        {{-- Запись голоса --}}
        <div id="recorder" class="mb-2 hidden items-center gap-3 rounded-lg bg-red-50 px-3 py-2">
            <span class="relative flex h-3 w-3 shrink-0">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75"></span>
                <span class="relative inline-flex h-3 w-3 rounded-full bg-red-500"></span>
            </span>

            <span class="text-sm text-red-800">Идёт запись</span>
            <span id="recorder-time" class="font-mono text-sm text-red-900">0:00</span>

            <div class="ml-auto flex gap-2">
                <button type="button" id="recorder-cancel"
                        class="h-10 rounded-lg px-3 text-sm text-gray-600 hover:bg-white">
                    Отмена
                </button>
                <button type="button" id="recorder-stop"
                        class="h-10 rounded-lg bg-red-600 px-4 text-sm font-medium text-white hover:bg-red-700">
                    Готово
                </button>
            </div>
        </div>

        <div id="composer-error" class="mb-2 hidden rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800"></div>

        <div class="flex items-end gap-1.5">
            <input type="file" id="file-input" class="hidden" multiple
                   accept="{{ collect(config('attachments.images'))
                               ->merge(config('attachments.audio'))
                               ->merge(config('attachments.documents'))
                               ->map(fn ($e) => '.'.$e)->implode(',') }},image/*,audio/*">

            {{-- Кнопки высотой 44px: меньше на сенсорном экране не попасть --}}
            <button type="button" id="attach-button"
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 active:bg-gray-200"
                    aria-label="Прикрепить файл" title="Прикрепить файл или картинку">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13"/>
                </svg>
            </button>

            <button type="button" id="record-button"
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 active:bg-gray-200"
                    aria-label="Записать голосовое" title="Записать голосовое сообщение">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z"/>
                </svg>
            </button>

            <textarea name="message" id="message" rows="1" maxlength="8000"
                      placeholder="Спросите что-нибудь…"
                      class="max-h-40 min-h-[44px] flex-1 resize-none rounded-2xl border-gray-300 px-4 py-2.5 text-base focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"></textarea>

            <button type="submit" id="send-button"
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-40"
                    aria-label="Отправить">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.125A59.769 59.769 0 0121.485 12 59.768 59.768 0 013.27 20.875L5.999 12zm0 0h7.5"/>
                </svg>
            </button>
        </div>

        <p id="thinking" class="mt-2 hidden text-xs text-gray-500">
            Помощник думает. После включения компьютера первый ответ может занять до минуты.
        </p>

        <p class="mt-1.5 hidden text-[11px] text-gray-400 sm:block">
            Enter — отправить, Shift+Enter — новая строка. Файлы можно перетащить сюда или вставить из буфера.
        </p>
    </form>
</div>
