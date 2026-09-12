<x-app-layout>
    <div class="max-w-3xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        @include('admin.partials.nav')
        <x-flash />

        <h1 class="text-lg font-medium text-gray-900 mb-4">Поведение помощника</h1>

        <form method="POST" action="{{ route('admin.settings.update') }}"
              class="rounded-lg border border-gray-200 bg-white p-6">
            @csrf
            @method('PATCH')

            <div class="mb-5">
                <label for="company_name" class="block text-sm font-medium text-gray-800">Название компании</label>
                <input type="text" name="company_name" id="company_name" maxlength="200"
                       value="{{ old('company_name', $companyName) }}"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <p class="mt-1 text-xs text-gray-500">Помощник будет знать, в какой организации он работает.</p>
            </div>

            <div class="mb-5">
                <label for="system_prompt" class="block text-sm font-medium text-gray-800">Инструкция помощнику</label>
                <textarea name="system_prompt" id="system_prompt" rows="16" required
                          class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500">{{ old('system_prompt', $systemPrompt) }}</textarea>
                <p class="mt-1 text-xs text-gray-500">
                    Правила, которым помощник следует в каждом диалоге. Список подключённых источников
                    подставляется автоматически — перечислять их здесь не нужно.
                </p>
            </div>

            <div class="mb-5">
                <label for="history_limit" class="block text-sm font-medium text-gray-800">Сколько прошлых сообщений помнить</label>
                <input type="number" name="history_limit" id="history_limit" min="2" max="50" required
                       value="{{ old('history_limit', $historyLimit) }}"
                       class="mt-1 block w-32 rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <p class="mt-1 text-xs text-gray-500">
                    Чем больше, тем лучше помощник держит нить разговора, но тем дольше думает. Разумно 10–16.
                </p>
            </div>

            <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                Сохранить
            </button>
        </form>

        <details class="mt-6">
            <summary class="cursor-pointer text-sm text-gray-500 hover:text-gray-700">Показать инструкцию по умолчанию</summary>
            <pre class="mt-2 whitespace-pre-wrap rounded-md bg-gray-50 p-4 text-xs text-gray-700">{{ $defaultPrompt }}</pre>
        </details>

        <div class="mt-6 rounded-lg border border-gray-200 bg-white p-5 text-sm text-gray-600">
            <h2 class="font-medium text-gray-900 mb-2">Параметры нейросети</h2>
            <p>Задаются в файле <code class="bg-gray-100 px-1">.env</code> на самом компьютере и требуют перезапуска приложения.</p>

            <ul class="mt-2 space-y-1">
                <li>Рантайм: <strong>{{ $llm['driver'] }}</strong> ({{ $llm['base_url'] }})</li>
                <li>Модель диалога: <strong>{{ config('llm.model') }}</strong></li>
                <li>Модель поиска: <strong>{{ config('llm.embedding_model') }}</strong></li>
                <li>Одновременных запросов к модели: <strong>{{ config('llm.max_concurrent') }}</strong></li>
            </ul>
        </div>
    </div>
</x-app-layout>
