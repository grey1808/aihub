<x-chat-layout>
    @php($limits = [
        'maxFiles' => (int) config('attachments.max_per_message'),
        'maxSize'  => (int) config('attachments.max_size'),
    ])

    {{-- Верхняя панель. Компактная: на телефоне каждый пиксель высоты
         отнимается у переписки. --}}
    <header class="flex h-14 shrink-0 items-center gap-2 border-b border-gray-200 bg-white px-2 sm:px-4">
        <button type="button" data-drawer-open
                class="flex h-11 w-11 items-center justify-center rounded-lg text-gray-600 hover:bg-gray-100 md:hidden"
                aria-label="Список чатов">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>

        <div class="min-w-0 flex-1">
            <div class="truncate text-sm font-medium text-gray-900">
                {{ $thread?->title ?? config('app.name') }}
            </div>
            @if ($thread)
                <div class="truncate text-xs text-gray-400">{{ config('app.name') }}</div>
            @endif
        </div>

        <form method="POST" action="{{ route('chat.store') }}" class="shrink-0">
            @csrf
            <button class="flex h-11 items-center gap-1 rounded-lg px-2 text-sm font-medium text-indigo-600 hover:bg-indigo-50"
                    aria-label="Новый чат">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                <span class="hidden sm:inline">Новый чат</span>
            </button>
        </form>

        <div class="relative shrink-0" data-menu>
            <button type="button" data-menu-toggle
                    class="flex h-11 w-11 items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700"
                    aria-label="Меню пользователя">
                {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
            </button>

            <div data-menu-panel
                 class="absolute right-0 top-12 z-30 hidden w-56 rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                <div class="border-b border-gray-100 px-4 py-2">
                    <div class="truncate text-sm font-medium text-gray-900">{{ auth()->user()->name }}</div>
                    <div class="truncate text-xs text-gray-500">{{ auth()->user()->email }}</div>
                </div>

                @if (auth()->user()->is_admin)
                    <a href="{{ route('admin.dashboard') }}" class="block px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                        Администрирование
                    </a>
                @endif

                <a href="{{ route('profile.edit') }}" class="block px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                    Профиль
                </a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="block w-full px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50">
                        Выйти
                    </button>
                </form>
            </div>
        </div>
    </header>

    <div class="flex min-h-0 flex-1">

        {{-- Список чатов: колонка на компьютере, выдвижная панель на телефоне --}}
        <div data-drawer-overlay
             class="fixed inset-0 z-40 hidden bg-black/40 md:hidden"></div>

        <aside data-drawer
               class="fixed inset-y-0 left-0 z-50 flex w-72 max-w-[85vw] -translate-x-full flex-col border-r border-gray-200 bg-white transition-transform duration-200 md:static md:z-auto md:w-64 md:translate-x-0">
            <div class="flex h-14 shrink-0 items-center justify-between border-b border-gray-100 px-4 md:hidden">
                <span class="text-sm font-medium text-gray-900">Мои чаты</span>
                <button type="button" data-drawer-close
                        class="flex h-10 w-10 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100"
                        aria-label="Закрыть">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <form method="POST" action="{{ route('chat.store') }}" class="shrink-0 p-3">
                @csrf
                <button class="w-full rounded-lg bg-indigo-600 px-3 py-2.5 text-sm font-medium text-white hover:bg-indigo-700">
                    + Новый чат
                </button>
            </form>

            <nav class="min-h-0 flex-1 overflow-y-auto pb-4">
                @forelse ($threads as $item)
                    <a href="{{ route('chat.show', $item) }}"
                       class="block border-b border-gray-50 px-4 py-3 text-sm hover:bg-gray-50 {{ $thread && $item->id === $thread->id ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-gray-700' }}">
                        <div class="truncate">{{ $item->title }}</div>
                        <div class="text-[11px] text-gray-400">{{ $item->last_message_at?->diffForHumans() }}</div>
                    </a>
                @empty
                    <p class="p-4 text-xs text-gray-400">Чатов пока нет.</p>
                @endforelse
            </nav>
        </aside>

        {{-- Переписка --}}
        <section class="flex min-h-0 min-w-0 flex-1 flex-col bg-gray-50">
            @if (! $thread)
                <div class="flex flex-1 flex-col items-center justify-center gap-4 px-6 text-center">
                    <h2 class="text-lg font-medium text-gray-800">Здравствуйте, {{ auth()->user()->name }}</h2>
                    <p class="max-w-md text-sm text-gray-500">
                        Задайте вопрос по документам компании. Можно приложить файл, картинку
                        или надиктовать голосом — помощник найдёт ответ и покажет, откуда его взял.
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
                    @forelse ($thread->messages as $message)
                        @include('chat.partials.message', ['message' => $message])
                    @empty
                        <p class="mt-8 text-center text-sm text-gray-400">Напишите первый вопрос.</p>
                    @endforelse
                </div>

                @include('chat.partials.composer', ['thread' => $thread, 'limits' => $limits])
            @endif
        </section>
    </div>
</x-chat-layout>
