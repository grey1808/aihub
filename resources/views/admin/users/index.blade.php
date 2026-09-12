<x-app-layout>
    <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
        @include('admin.partials.nav')
        <x-flash />

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-lg font-medium text-gray-900">Пользователи</h1>

            <a href="{{ route('admin.users.create') }}"
               class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                + Добавить пользователя
            </a>
        </div>

        <p class="mb-4 max-w-3xl text-sm text-gray-600">
            Регистрация открыта — сотрудники заводят учётные записи сами. Здесь можно завести
            пользователя вручную, сменить ему пароль, поправить должность, выдать права
            администратора или закрыть доступ.
        </p>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Сотрудник</th>
                        <th class="px-4 py-3">Вход</th>
                        <th class="px-4 py-3">Вопросов</th>
                        <th class="px-4 py-3">Файлов</th>
                        <th class="px-4 py-3">Последняя активность</th>
                        <th class="px-4 py-3">Состояние</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @foreach ($users as $user)
                        <tr class="{{ $user->is_active ? '' : 'opacity-50' }}">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.users.edit', $user) }}"
                                   class="font-medium text-indigo-600 hover:underline">{{ $user->name }}</a>

                                @if ($user->role())
                                    <div class="text-xs text-gray-500">{{ $user->role() }}</div>
                                @else
                                    <div class="text-xs text-amber-600">должность не указана</div>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-gray-600">
                                {{ $user->login ?? $user->email }}
                            </td>

                            <td class="px-4 py-3 text-gray-600">{{ $messages[$user->id] ?? 0 }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $files[$user->id] ?? 0 }}</td>

                            <td class="px-4 py-3 text-gray-600">
                                @if (! empty($lastSeen[$user->id]))
                                    {{ \Carbon\Carbon::parse($lastSeen[$user->id])->diffForHumans() }}
                                @else
                                    <span class="text-gray-400">не пользовался</span>
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                @if ($user->is_admin)
                                    <span class="rounded bg-indigo-50 px-2 py-0.5 text-xs text-indigo-700">администратор</span>
                                @endif

                                @unless ($user->is_active)
                                    <span class="rounded bg-red-50 px-2 py-0.5 text-xs text-red-700">доступ закрыт</span>
                                @endunless

                                @if ($user->is_active && ! $user->is_admin)
                                    <span class="text-xs text-gray-400">сотрудник</span>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('admin.users.edit', $user) }}"
                                   class="text-xs text-indigo-600 hover:underline">настроить</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-gray-500">
            Переписку сотрудников и их личные заметки помощника администратор не видит —
            это личные данные, и в интерфейсе их нет намеренно.
        </p>
    </div>
</x-app-layout>
