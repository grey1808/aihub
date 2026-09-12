{{-- Верхняя панель чата: название, проект и меню действий --}}
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

        @if ($thread?->project)
            <a href="{{ route('projects.show', $thread->project) }}"
               class="truncate text-xs text-indigo-600 hover:underline">
                проект «{{ $thread->project->name }}»
            </a>
        @elseif ($thread)
            <div class="truncate text-xs text-gray-400">{{ config('app.name') }}</div>
        @endif
    </div>

    @if ($thread)
        {{-- Действия с чатом. Раньше их не было видно вовсе — удалить чат
             было нечем, хотя маршрут существовал. --}}
        <div class="relative shrink-0" data-thread-menu>
            <button type="button" data-thread-menu-toggle
                    class="flex h-11 w-11 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100"
                    aria-label="Действия с чатом">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 12.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 18.75a.75.75 0 110-1.5.75.75 0 010 1.5z"/>
                </svg>
            </button>

            <div data-thread-menu-panel
                 class="absolute right-0 top-12 z-30 hidden w-72 rounded-lg border border-gray-200 bg-white py-1 shadow-lg">

                <form method="POST" action="{{ route('chat.rename', $thread) }}" class="border-b border-gray-100 p-3">
                    @csrf
                    @method('PATCH')

                    <label for="rename" class="block text-xs text-gray-500">Название чата</label>
                    <div class="mt-1 flex gap-1">
                        <input type="text" name="title" id="rename" required maxlength="120"
                               value="{{ $thread->title }}"
                               class="min-w-0 flex-1 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <button class="shrink-0 rounded-md bg-gray-800 px-3 text-sm text-white hover:bg-gray-900">ОК</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('chat.move', $thread) }}" class="border-b border-gray-100 p-3">
                    @csrf
                    @method('PATCH')

                    <label for="move" class="block text-xs text-gray-500">Проект</label>
                    <div class="mt-1 flex gap-1">
                        <select name="project_id" id="move"
                                class="min-w-0 flex-1 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">— без проекта —</option>
                            @foreach ($projects as $project)
                                <option value="{{ $project->id }}" @selected($thread->project_id === $project->id)>
                                    {{ $project->name }}
                                </option>
                            @endforeach
                        </select>
                        <button class="shrink-0 rounded-md bg-gray-800 px-3 text-sm text-white hover:bg-gray-900">ОК</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('chat.destroy', $thread) }}"
                      onsubmit="return confirm('Удалить чат «{{ $thread->title }}»? Его можно будет вернуть из корзины.')">
                    @csrf
                    @method('DELETE')
                    <button class="block w-full px-4 py-2.5 text-left text-sm text-red-600 hover:bg-red-50">
                        Удалить чат
                    </button>
                </form>

                <p class="px-4 pb-2 text-[11px] text-gray-400">
                    Удалённый чат лежит в корзине {{ config('chat.trash_lifetime_days') }} дней.
                </p>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('chat.store') }}" class="shrink-0">
        @csrf
        <input type="hidden" name="project_id" value="{{ $thread?->project_id }}">
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
                <div class="truncate text-xs text-gray-500">{{ auth()->user()->position ?: auth()->user()->email }}</div>
            </div>

            @if (auth()->user()->is_admin)
                <a href="{{ route('admin.dashboard') }}" class="block px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                    Администрирование
                </a>
            @endif

            <a href="{{ route('profile.edit') }}" class="block px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                Профиль и память
            </a>

            @if ($trashed > 0)
                <a href="{{ route('chat.trash') }}" class="block px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                    Корзина ({{ $trashed }})
                </a>
            @endif

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="block w-full px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50">
                    Выйти
                </button>
            </form>
        </div>
    </div>
</header>
