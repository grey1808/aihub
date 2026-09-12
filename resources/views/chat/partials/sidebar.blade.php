{{-- Боковая панель: проекты-папки, чаты вне проектов, корзина --}}
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

<div class="shrink-0 space-y-2 p-3">
    <form method="POST" action="{{ route('chat.store') }}">
        @csrf
        <input type="hidden" name="project_id" value="{{ $thread?->project_id }}">
        <button class="w-full rounded-lg bg-indigo-600 px-3 py-2.5 text-sm font-medium text-white hover:bg-indigo-700">
            + Новый чат
        </button>
    </form>

    <details class="group">
        <summary class="cursor-pointer list-none rounded-lg border border-dashed border-gray-300 px-3 py-2 text-center text-sm text-gray-500 hover:border-indigo-400 hover:text-indigo-600">
            + Новый проект
        </summary>

        <form method="POST" action="{{ route('projects.store') }}" class="mt-2 flex gap-1">
            @csrf
            <input type="text" name="name" required maxlength="120" placeholder="Название темы"
                   class="min-w-0 flex-1 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <button class="shrink-0 rounded-md bg-gray-800 px-3 text-sm text-white hover:bg-gray-900">ОК</button>
        </form>
    </details>
</div>

<nav class="min-h-0 flex-1 overflow-y-auto pb-4">
    @foreach ($projects as $project)
        @php($isCurrent = $thread?->project_id === $project->id)

        <details class="border-b border-gray-50" @if ($isCurrent) open @endif>
            <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-2.5 text-sm hover:bg-gray-50">
                <svg class="h-4 w-4 shrink-0 text-gray-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"/>
                </svg>

                <span class="min-w-0 flex-1 truncate {{ $isCurrent ? 'font-medium text-indigo-700' : 'text-gray-800' }}">
                    {{ $project->name }}
                </span>

                <span class="shrink-0 text-[11px] text-gray-400">{{ $project->threads->count() }}</span>
            </summary>

            <div class="bg-gray-50/60 pb-1">
                <a href="{{ route('projects.show', $project) }}"
                   class="block px-9 py-1.5 text-xs text-gray-500 hover:text-indigo-600">
                    Настройки проекта
                </a>

                @forelse ($project->threads as $item)
                    <a href="{{ route('chat.show', $item) }}"
                       class="block px-9 py-2 text-sm hover:bg-white {{ $thread && $item->id === $thread->id ? 'bg-white font-medium text-indigo-700' : 'text-gray-700' }}">
                        <span class="block truncate">{{ $item->title }}</span>
                        <span class="text-[11px] text-gray-400">{{ $item->last_message_at?->diffForHumans() }}</span>
                    </a>
                @empty
                    <p class="px-9 py-2 text-xs text-gray-400">Чатов пока нет</p>
                @endforelse

                <form method="POST" action="{{ route('chat.store') }}" class="px-9 py-1.5">
                    @csrf
                    <input type="hidden" name="project_id" value="{{ $project->id }}">
                    <button class="text-xs text-indigo-600 hover:underline">+ чат в этом проекте</button>
                </form>
            </div>
        </details>
    @endforeach

    @if ($projects->isNotEmpty() && $threads->isNotEmpty())
        <p class="px-3 pb-1 pt-3 text-[11px] uppercase tracking-wide text-gray-400">Без проекта</p>
    @endif

    @forelse ($threads as $item)
        <a href="{{ route('chat.show', $item) }}"
           class="block border-b border-gray-50 px-4 py-3 text-sm hover:bg-gray-50 {{ $thread && $item->id === $thread->id ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-gray-700' }}">
            <span class="block truncate">{{ $item->title }}</span>
            <span class="text-[11px] text-gray-400">{{ $item->last_message_at?->diffForHumans() }}</span>
        </a>
    @empty
        @if ($projects->isEmpty())
            <p class="p-4 text-xs text-gray-400">Чатов пока нет.</p>
        @endif
    @endforelse

    @if ($trashed > 0)
        <a href="{{ route('chat.trash') }}"
           class="mt-2 flex items-center gap-2 px-4 py-3 text-sm text-gray-500 hover:bg-gray-50 hover:text-gray-800">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/>
            </svg>
            Корзина
            <span class="ml-auto text-[11px] text-gray-400">{{ $trashed }}</span>
        </a>
    @endif
</nav>
