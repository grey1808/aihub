<x-app-layout>
    <div class="mx-auto max-w-2xl px-4 py-6 sm:px-6 lg:px-8">
        @include('admin.partials.nav')
        <x-flash />

        <div class="mb-4 flex items-center justify-between">
            <div>
                <h1 class="text-lg font-medium text-gray-900">{{ $user->name }}</h1>
                <p class="text-xs text-gray-500">
                    Зарегистрирован {{ $user->created_at->format('d.m.Y') }} · чатов: {{ $threads }}
                </p>
            </div>

            <a href="{{ route('admin.users.index') }}" class="text-sm text-gray-500 hover:underline">← ко всем</a>
        </div>

        <form method="POST" action="{{ route('admin.users.update', $user) }}"
              class="rounded-lg border border-gray-200 bg-white p-6">
            @csrf
            @method('PATCH')

            @include('admin.users.fields', ['user' => $user])

            <hr class="my-6 border-gray-100">

            <div class="space-y-3">
                <label class="flex items-center gap-2 text-sm text-gray-800">
                    <input type="hidden" name="is_admin" value="0">
                    <input type="checkbox" name="is_admin" value="1" @checked(old('is_admin', $user->is_admin))
                           class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    Администратор
                </label>

                <label class="flex items-center gap-2 text-sm text-gray-800">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active))
                           class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    Доступ разрешён
                </label>

                <p class="text-xs text-gray-500">
                    Если снять галочку доступа, сотрудник не сможет войти, но его чаты сохранятся.
                </p>

                @if ($lastAdmin)
                    <p class="rounded bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        Это единственный администратор — снять с него права или доступ нельзя,
                        пока не назначен другой.
                    </p>
                @endif
            </div>

            <div class="mt-6">
                <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    Сохранить
                </button>
            </div>
        </form>

        <div class="mt-6 rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="text-sm font-medium text-gray-900">Сменить пароль</h2>
            <p class="mt-1 text-sm text-gray-600">
                Пригодится, когда сотрудник забыл свой: почтового сервера на моноблоке нет,
                восстановить пароль письмом не выйдет.
            </p>

            <form method="POST" action="{{ route('admin.users.password', $user) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                @csrf

                <div>
                    <label for="new_password" class="block text-sm font-medium text-gray-800">Новый пароль</label>
                    <input type="password" name="password" id="new_password" required autocomplete="new-password"
                           class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="new_password_confirmation" class="block text-sm font-medium text-gray-800">Повторите</label>
                    <input type="password" name="password_confirmation" id="new_password_confirmation" required
                           autocomplete="new-password"
                           class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div class="sm:col-span-2">
                    <button class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">
                        Сменить пароль
                    </button>
                </div>
            </form>
        </div>

        <div class="mt-6 rounded-lg border border-red-200 bg-white p-6">
            <h2 class="text-sm font-medium text-red-900">Удалить пользователя</h2>
            <p class="mt-1 text-sm text-gray-600">
                Вместе с ним удалятся все его чаты и приложенные файлы. Отменить нельзя.
                Если нужно просто закрыть доступ — снимите галочку выше, это обратимо.
            </p>

            <form method="POST" action="{{ route('admin.users.destroy', $user) }}" class="mt-4"
                  onsubmit="return confirm('Удалить {{ $user->name }} вместе со всей перепиской?')">
                @csrf
                @method('DELETE')

                <button class="rounded-md border border-red-300 px-4 py-2 text-sm text-red-700 hover:bg-red-50">
                    Удалить
                </button>
            </form>
        </div>
    </div>
</x-app-layout>
