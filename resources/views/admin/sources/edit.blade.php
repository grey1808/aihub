<x-app-layout>
    <div class="max-w-3xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        @include('admin.partials.nav')
        <x-flash />

        <div class="flex items-center justify-between mb-4">
            <h1 class="text-lg font-medium text-gray-900">{{ $source->name }}</h1>
            <a href="{{ route('admin.sources.index') }}" class="text-sm text-gray-500 hover:underline">← ко всем источникам</a>
        </div>

        <div class="mb-4 flex flex-wrap gap-3">
            <form method="POST" action="{{ route('admin.sources.test', $source) }}">
                @csrf
                <button class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm hover:bg-gray-50">
                    Проверить подключение
                </button>
            </form>

            <form method="POST" action="{{ route('admin.sources.sync', $source) }}">
                @csrf
                <button class="rounded-md border border-indigo-300 bg-indigo-50 px-4 py-2 text-sm text-indigo-700 hover:bg-indigo-100">
                    Синхронизировать сейчас
                </button>
            </form>

            <form method="POST" action="{{ route('admin.sources.destroy', $source) }}"
                  onsubmit="return confirm('Удалить источник и все его документы из базы знаний?')">
                @csrf
                @method('DELETE')
                <button class="rounded-md border border-red-200 bg-white px-4 py-2 text-sm text-red-700 hover:bg-red-50">
                    Удалить источник
                </button>
            </form>
        </div>

        <form method="POST" action="{{ route('admin.sources.update', $source) }}"
              class="rounded-lg border border-gray-200 bg-white p-6">
            @csrf
            @method('PATCH')

            <p class="mb-5 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-600">
                Тип: {{ $registry->label($source->type) }}. Документов в индексе: {{ $source->documents_count }}.
            </p>

            @include('admin.sources.form', [
                'registry' => $registry,
                'type'     => $source->type,
                'source'   => $source,
                'values'   => $source->plainConfig(),
            ])

            <div class="mt-6">
                <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    Сохранить
                </button>
            </div>
        </form>

        <h2 class="mt-8 mb-3 text-sm font-medium text-gray-800">История обновлений</h2>

        @if ($runs->isEmpty())
            <p class="text-sm text-gray-500">Источник ещё ни разу не синхронизировался.</p>
        @else
            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-3 py-2">Когда</th>
                            <th class="px-3 py-2">Итог</th>
                            <th class="px-3 py-2">Найдено</th>
                            <th class="px-3 py-2">Новых</th>
                            <th class="px-3 py-2">Изменённых</th>
                            <th class="px-3 py-2">Без изменений</th>
                            <th class="px-3 py-2">Удалено</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @foreach ($runs as $run)
                            <tr>
                                <td class="px-3 py-2 text-gray-600">{{ $run->started_at?->format('d.m.Y H:i') }}</td>
                                <td class="px-3 py-2">
                                    <span class="{{ $run->status === 'ok' ? 'text-green-700' : ($run->status === 'error' ? 'text-red-700' : 'text-blue-700') }}">
                                        {{ ['ok' => 'успешно', 'error' => 'ошибка', 'running' => 'идёт'][$run->status] ?? $run->status }}
                                    </span>
                                    @if ($run->message)
                                        <div class="text-xs text-red-600">{{ Str::limit($run->message, 120) }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2">{{ $run->found }}</td>
                                <td class="px-3 py-2">{{ $run->created }}</td>
                                <td class="px-3 py-2">{{ $run->updated }}</td>
                                <td class="px-3 py-2">{{ $run->skipped }}</td>
                                <td class="px-3 py-2">{{ $run->deleted }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-app-layout>
