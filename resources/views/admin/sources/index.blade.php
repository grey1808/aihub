<x-app-layout>
    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        @include('admin.partials.nav')
        <x-flash />

        <div class="flex items-center justify-between mb-4">
            <h1 class="text-lg font-medium text-gray-900">Источники знаний</h1>

            <a href="{{ route('admin.sources.create') }}"
               class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                + Добавить источник
            </a>
        </div>

        <p class="mb-4 text-sm text-gray-600 max-w-3xl">
            Источник — это место, откуда помощник берёт знания: папка с документами, Confluence, база 1С.
            Источников может быть сколько угодно. Не забудьте заполнить поле «Что здесь лежит» —
            именно по нему помощник понимает, куда идти за ответом.
        </p>

        @if ($sources->isEmpty())
            <div class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center">
                <p class="text-sm text-gray-500">Источников пока нет. Пока их нет, помощник отвечает только общими знаниями.</p>
            </div>
        @else
            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-3">№</th>
                            <th class="px-4 py-3">Название</th>
                            <th class="px-4 py-3">Тип</th>
                            <th class="px-4 py-3">Документов</th>
                            <th class="px-4 py-3">Обновление</th>
                            <th class="px-4 py-3">Состояние</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @foreach ($sources as $source)
                            <tr class="{{ $source->is_enabled ? '' : 'opacity-50' }}">
                                <td class="px-4 py-3 text-gray-400">{{ $source->id }}</td>

                                <td class="px-4 py-3">
                                    <a href="{{ route('admin.sources.edit', $source) }}"
                                       class="font-medium text-indigo-600 hover:underline">{{ $source->name }}</a>

                                    @unless ($source->is_enabled)
                                        <span class="ml-1 text-xs text-gray-400">(выключен)</span>
                                    @endunless

                                    @if ($source->description)
                                        <div class="text-xs text-gray-500">{{ Str::limit($source->description, 90) }}</div>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-gray-600">{{ $registry->label($source->type) }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $source->documents_count }}</td>

                                <td class="px-4 py-3 text-gray-600">
                                    {{ $source->sync_interval_minutes > 0
                                        ? 'каждые '.$source->sync_interval_minutes.' мин'
                                        : 'вручную' }}

                                    <div class="text-xs text-gray-400">
                                        {{ $source->last_synced_at?->diffForHumans() ?? 'ни разу' }}
                                    </div>
                                </td>

                                <td class="px-4 py-3">
                                    @php($colors = ['ok' => 'text-green-700', 'error' => 'text-red-700', 'running' => 'text-blue-700'])
                                    <span class="{{ $colors[$source->last_status] ?? 'text-gray-400' }}">
                                        {{ ['ok' => 'в порядке', 'error' => 'ошибка', 'running' => 'обновляется'][$source->last_status] ?? 'не запускался' }}
                                    </span>

                                    @if ($source->last_error)
                                        <div class="text-xs text-red-500">{{ Str::limit($source->last_error, 60) }}</div>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <form method="POST" action="{{ route('admin.sources.sync', $source) }}" class="inline">
                                        @csrf
                                        <button class="text-xs text-indigo-600 hover:underline">Обновить</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-app-layout>
