<x-app-layout>
    <div class="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h1 class="text-lg font-medium text-gray-900">{{ $project->name }}</h1>
                <p class="text-xs text-gray-500">Проект · чатов: {{ $threads->count() }}</p>
            </div>

            <a href="{{ route('chat.index') }}" class="text-sm text-gray-500 hover:underline">← к чатам</a>
        </div>

        <x-flash />

        <div class="mb-6 rounded-lg border border-indigo-100 bg-indigo-50/50 p-4 text-sm text-gray-700">
            Всё, что здесь настроено, действует во <strong>всех чатах этого проекта</strong>.
            Сами чаты друг друга не читают — каждый ведёт свой разговор, но общий контекст
            берут отсюда.
        </div>

        <form method="POST" action="{{ route('projects.update', $project) }}"
              class="space-y-6 rounded-lg border border-gray-200 bg-white p-6">
            @csrf
            @method('PATCH')

            <div>
                <label for="name" class="block text-sm font-medium text-gray-800">Название</label>
                <input type="text" name="name" id="name" required maxlength="120"
                       value="{{ old('name', $project->name) }}"
                       class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-gray-800">О чём проект</label>
                <textarea name="description" id="description" rows="2"
                          placeholder="Например: подготовка квартальной отчётности за 3 квартал"
                          class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $project->description) }}</textarea>
                <p class="mt-1 text-xs text-gray-500">Коротко, одной фразой. Помощник это тоже читает.</p>
            </div>

            <div>
                <label for="instructions" class="block text-sm font-medium text-gray-800">Инструкция помощнику</label>
                <textarea name="instructions" id="instructions" rows="6"
                          placeholder="Отвечай со ссылками на пункты регламента.&#10;Суммы приводи с НДС.&#10;Если данных за период нет — так и говори, не оценивай приблизительно."
                          class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('instructions', $project->instructions) }}</textarea>
                <p class="mt-1 text-xs text-gray-500">
                    Как вести себя именно в этом проекте. Добавляется к общим правилам,
                    заданным администратором.
                </p>
            </div>

            <div>
                <label for="notes" class="block text-sm font-medium text-gray-800">Заметки по проекту</label>
                <textarea name="notes" id="notes" rows="8"
                          placeholder="- Сверку делаем по данным на последний день месяца&#10;- Контрагент «Ромашка» — договор 77-А, отсрочка 10 дней"
                          class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('notes', $project->notes) }}</textarea>
                <p class="mt-1 text-xs text-gray-500">
                    Общая память проекта: помощник читает её в каждом чате и дополняет сам,
                    когда вы просите что-то запомнить.
                    @if ($project->notes_updated_at)
                        Последнее изменение: {{ $project->notes_updated_at->diffForHumans() }}.
                    @endif
                </p>
            </div>

            <div>
                <span class="block text-sm font-medium text-gray-800">Где искать</span>
                <p class="mt-1 text-xs text-gray-500">
                    Если ничего не отмечено — ищем во всех источниках. Отметьте нужные,
                    чтобы помощник не тянул в ответы лишнее из других отделов.
                </p>

                <div class="mt-2 space-y-2">
                    @forelse ($sources as $source)
                        <label class="flex items-start gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="source_ids[]" value="{{ $source->id }}"
                                   @checked(in_array($source->id, old('source_ids', $project->sourceIds())))
                                   class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            <span>
                                {{ $source->name }}
                                <span class="text-xs text-gray-400">— {{ $registry->label($source->type) }}</span>
                                @if ($source->description)
                                    <span class="block text-xs text-gray-500">{{ Str::limit($source->description, 100) }}</span>
                                @endif
                            </span>
                        </label>
                    @empty
                        <p class="text-sm text-gray-500">Источники ещё не настроены администратором.</p>
                    @endforelse
                </div>
            </div>

            <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                Сохранить
            </button>
        </form>

        <div class="mt-6 rounded-lg border border-gray-200 bg-white p-6">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium text-gray-900">Чаты проекта</h2>

                <form method="POST" action="{{ route('chat.store') }}">
                    @csrf
                    <input type="hidden" name="project_id" value="{{ $project->id }}">
                    <button class="text-sm text-indigo-600 hover:underline">+ новый чат</button>
                </form>
            </div>

            @if ($threads->isEmpty())
                <p class="mt-3 text-sm text-gray-500">Чатов пока нет.</p>
            @else
                <ul class="mt-3 divide-y divide-gray-100">
                    @foreach ($threads as $item)
                        <li class="py-2">
                            <a href="{{ route('chat.show', $item) }}" class="text-sm text-indigo-600 hover:underline">
                                {{ $item->title }}
                            </a>
                            <span class="text-xs text-gray-400">· {{ $item->last_message_at?->diffForHumans() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($available->isNotEmpty())
            <div class="mt-6 rounded-lg border border-gray-200 bg-white p-6">
                <h2 class="text-sm font-medium text-gray-900">Добавить существующие чаты</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Отметьте те, что относятся к этой теме. Переписка сохранится,
                    просто чат переедет в папку.
                </p>

                <form method="POST" action="{{ route('projects.attach', $project) }}" class="mt-4">
                    @csrf

                    <div class="max-h-72 space-y-1 overflow-y-auto rounded-md border border-gray-100 p-2">
                        @foreach ($available as $item)
                            <label class="flex items-start gap-2 rounded px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
                                <input type="checkbox" name="threads[]" value="{{ $item->id }}"
                                       class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                <span class="min-w-0">
                                    <span class="block truncate">{{ $item->title }}</span>
                                    <span class="block text-xs text-gray-400">
                                        {{ $item->last_message_at?->diffForHumans() }}
                                        @if ($item->project_id)
                                            · сейчас в проекте «{{ $item->project?->name }}»
                                        @endif
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <button class="mt-4 rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">
                        Добавить отмеченные
                    </button>
                </form>
            </div>
        @endif

        <div class="mt-6 rounded-lg border border-red-200 bg-white p-6">
            <h2 class="text-sm font-medium text-red-900">Удалить проект</h2>
            <p class="mt-1 text-sm text-gray-600">
                Чаты не пропадут — они просто выйдут из папки и останутся в общем списке.
                Инструкция и заметки проекта будут потеряны.
            </p>

            <form method="POST" action="{{ route('projects.destroy', $project) }}" class="mt-4"
                  onsubmit="return confirm('Удалить проект «{{ $project->name }}»? Чаты останутся.')">
                @csrf
                @method('DELETE')
                <button class="rounded-md border border-red-300 px-4 py-2 text-sm text-red-700 hover:bg-red-50">
                    Удалить проект
                </button>
            </form>
        </div>
    </div>
</x-app-layout>
