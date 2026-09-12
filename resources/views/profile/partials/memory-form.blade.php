<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">Память помощника</h2>

        <p class="mt-1 text-sm text-gray-600">
            Заметки, которые помощник читает <strong>перед каждым ответом</strong>, в любом чате.
            Сюда он записывает то, что вы просите запомнить: «запомни, что я веду учёт по ООО „Ромашка“»,
            «всегда сначала смотри в папку с регламентами», «отчёты мне нужны таблицей».
        </p>

        <p class="mt-2 text-sm text-gray-600">
            Можно править руками. Эти заметки видите только вы — ни другие сотрудники,
            ни администратор их не читают.
        </p>
    </header>

    <form method="post" action="{{ route('profile.memory') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="memory" :value="'Заметки'" />

            <textarea id="memory" name="memory" rows="12"
                      placeholder="- Веду учёт по ООО «Ромашка» и ИП Смирнов&#10;- Прежде чем отвечать, всегда смотри регламенты бухгалтерии&#10;- Отчёты нужны таблицей, без длинных пояснений"
                      class="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('memory', $user->memory) }}</textarea>

            <p class="mt-1 text-xs text-gray-500">
                По одной мысли в строке. Чем короче и конкретнее, тем лучше помощник их применяет.
                @if ($user->memory_updated_at)
                    Последнее изменение: {{ $user->memory_updated_at->diffForHumans() }}.
                @endif
            </p>

            <x-input-error class="mt-2" :messages="$errors->get('memory')" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>Сохранить заметки</x-primary-button>

            @if (session('status') === 'memory-updated')
                <p class="text-sm text-gray-600">Сохранено.</p>
            @endif
        </div>
    </form>
</section>
