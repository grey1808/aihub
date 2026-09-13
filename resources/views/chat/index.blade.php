<x-chat-layout>
    @php($limits = [
        'maxFiles' => (int) config('attachments.max_per_message'),
        'maxSize'  => (int) config('attachments.max_size'),
    ])

    @include('chat.partials.header')

    <div class="flex min-h-0 flex-1">
        <div data-drawer-overlay class="fixed inset-0 z-40 hidden bg-black/40 md:hidden"></div>

        <aside data-drawer
               class="fixed inset-y-0 left-0 z-50 flex w-72 max-w-[85vw] -translate-x-full flex-col border-r border-gray-200 bg-white transition-transform duration-200 md:static md:z-auto md:w-72 md:translate-x-0">
            @include('chat.partials.sidebar')
        </aside>

        {{-- Разделитель: тянется мышью, ширина запоминается в браузере.
             Двойной щелчок возвращает ширину по умолчанию. --}}
        <div data-resizer
             class="hidden w-1 shrink-0 cursor-col-resize bg-gray-200 transition-colors hover:bg-indigo-400 md:block"
             title="Потяните, чтобы изменить ширину. Двойной щелчок — вернуть как было"></div>

        <section class="flex min-h-0 min-w-0 flex-1 flex-col bg-gray-50">
            @if (session('status') || session('error'))
                <div class="border-b px-4 py-2 text-sm {{ session('error') ? 'border-red-200 bg-red-50 text-red-800' : 'border-green-200 bg-green-50 text-green-800' }}">
                    {{ session('error') ?? session('status') }}
                </div>
            @endif

            @if (! $thread)
                <div class="flex flex-1 flex-col items-center justify-center gap-4 px-6 text-center">
                    <h2 class="text-lg font-medium text-gray-800">Здравствуйте, {{ auth()->user()->callName() }}</h2>
                    <p class="max-w-md text-sm text-gray-500">
                        Задайте вопрос по документам компании. Можно приложить файл, картинку
                        или надиктовать голосом — помощник найдёт ответ и покажет, откуда его взял.
                    </p>
                    <p class="max-w-md text-xs text-gray-400">
                        Если тема большая и надолго — заведите проект: в нём чаты лежат вместе,
                        а помощник помнит общий контекст и следует вашей инструкции.
                    </p>

                    <form method="POST" action="{{ route('chat.store') }}">
                        @csrf
                        <button class="rounded-lg bg-indigo-600 px-5 py-3 text-sm font-medium text-white hover:bg-indigo-700">
                            Начать чат
                        </button>
                    </form>
                </div>
            @else
                <div id="messages" class="min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain p-3 sm:p-4">
                    @if ($thread->project && $thread->messages->isEmpty())
                        <div class="mx-auto max-w-2xl rounded-lg border border-indigo-100 bg-indigo-50/50 p-3 text-sm text-gray-700">
                            <p class="font-medium text-indigo-900">Проект «{{ $thread->project->name }}»</p>
                            @if ($thread->project->description)
                                <p class="mt-1 text-gray-600">{{ $thread->project->description }}</p>
                            @endif
                            <p class="mt-1 text-xs text-gray-500">
                                Помощник знает инструкцию и заметки этого проекта во всех его чатах.
                            </p>
                        </div>
                    @endif

                    @forelse ($thread->messages as $message)
                        @include('chat.partials.message', ['message' => $message])
                    @empty
                        @unless ($thread->project)
                            <p class="mt-8 text-center text-sm text-gray-400">Напишите первый вопрос.</p>
                        @endunless
                    @endforelse
                </div>

                @include('chat.partials.composer', ['thread' => $thread, 'limits' => $limits])
            @endif
        </section>
    </div>
</x-chat-layout>
