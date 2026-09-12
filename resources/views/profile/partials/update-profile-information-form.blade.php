<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">Кто вы</h2>

        <p class="mt-1 text-sm text-gray-600">
            Помощник учитывает это в каждом ответе: обращается по имени и подбирает
            ответ под вашу работу. Бухгалтеру и кладовщику на один и тот же вопрос
            нужны разные ответы.
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" :value="'Имя и фамилия'" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                          :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="preferred_name" :value="'Как к вам обращаться'" />
            <x-text-input id="preferred_name" name="preferred_name" type="text" class="mt-1 block w-full"
                          :value="old('preferred_name', $user->preferred_name)"
                          placeholder="Ольга Петровна" />
            <p class="mt-1 text-xs text-gray-500">
                Необязательно. Если не заполнить, помощник будет обращаться по имени выше.
            </p>
            <x-input-error class="mt-2" :messages="$errors->get('preferred_name')" />
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div>
                <x-input-label for="position" :value="'Должность'" />
                <x-text-input id="position" name="position" type="text" class="mt-1 block w-full"
                              :value="old('position', $user->position)"
                              placeholder="Бухгалтер по расчётам с поставщиками" />
                <p class="mt-1 text-xs text-gray-500">
                    Необязательно, но с ней ответы заметно точнее.
                </p>
                <x-input-error class="mt-2" :messages="$errors->get('position')" />
            </div>

            <div>
                <x-input-label for="department" :value="'Отдел'" />
                <x-text-input id="department" name="department" type="text" class="mt-1 block w-full"
                              :value="old('department', $user->department)"
                              placeholder="Бухгалтерия" />
                <x-input-error class="mt-2" :messages="$errors->get('department')" />
            </div>
        </div>

        <div>
            <x-input-label for="email" :value="'Электронная почта'" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full"
                          :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>Сохранить</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p class="text-sm text-gray-600">Сохранено.</p>
            @endif
        </div>
    </form>
</section>
