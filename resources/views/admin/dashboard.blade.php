<x-app-layout>
    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        @include('admin.partials.nav')
        <x-flash />

        <div class="mb-6 rounded-lg border bg-white p-5 {{ $llm['ok'] ? 'border-gray-200' : 'border-red-300' }}">
            <h2 class="font-medium text-gray-900 mb-2">Нейросеть</h2>

            @if ($llm['ok'])
                <p class="text-sm text-green-700">Работает: {{ $llm['driver'] }} — {{ $llm['base_url'] }}</p>

                <ul class="mt-2 text-sm text-gray-600 space-y-1">
                    <li>
                        Модель для диалога «{{ config('llm.model') }}»:
                        {!! $llm['chat_model_loaded']
                            ? '<span class="text-green-700">загружена</span>'
                            : '<span class="text-red-700">не найдена</span>' !!}
                    </li>
                    <li>
                        Модель для поиска «{{ config('llm.embedding_model') }}»:
                        {!! $llm['embed_model_loaded']
                            ? '<span class="text-green-700">загружена</span>'
                            : '<span class="text-red-700">не найдена</span>' !!}
                    </li>
                </ul>

                @if (! $llm['chat_model_loaded'] || ! $llm['embed_model_loaded'])
                    <p class="mt-2 text-sm text-gray-600">
                        Загрузить модель:
                        <code class="bg-gray-100 px-1">docker compose exec ollama ollama pull имя-модели</code>
                    </p>
                @endif
            @else
                <p class="text-sm text-red-700">
                    Не отвечает по адресу {{ $llm['base_url'] }}: {{ $llm['error'] ?? '' }}
                </p>
                <p class="mt-1 text-sm text-gray-600">
                    Пока нейросеть недоступна, чат работать не будет. Проверьте, запущен ли контейнер ollama
                    (или LM Studio, если выбран он).
                </p>
            @endif
        </div>

        <div class="mb-6 rounded-lg border border-gray-200 bg-white p-5">
            <h2 class="font-medium text-gray-900 mb-2">Вложения в чате</h2>

            <ul class="text-sm text-gray-600 space-y-1">
                <li>
                    Картинки:
                    @if (! trim((string) config('llm.vision_model')))
                        <span class="text-amber-700">модель со зрением не подключена</span>
                        — картинки принимаются, но помощник их не видит.
                        Укажите <code class="bg-gray-100 px-1">LLM_VISION_MODEL</code> в .env
                        (например <code class="bg-gray-100 px-1">qwen2.5vl:7b</code>).
                    @else
                        <span class="text-green-700">{{ config('llm.vision_model') }}</span>
                    @endif
                </li>
                <li>
                    Голосовые:
                    @if ($stt['ok'])
                        <span class="text-green-700">распознаются</span>
                        ({{ config('attachments.stt.model') }})
                    @else
                        <span class="text-red-700">недоступны</span> — {{ $stt['error'] ?? '' }}.
                        Запустите: <code class="bg-gray-100 px-1">docker compose --profile with-whisper up -d</code>
                    @endif
                </li>
                <li>
                    Запись голоса в браузере работает только по HTTPS.
                    @if (request()->secure())
                        <span class="text-green-700">Сейчас страница открыта по HTTPS — всё в порядке.</span>
                    @else
                        <span class="text-amber-700">Сейчас HTTP</span> — с других компьютеров микрофон будет недоступен.
                        Включается: <code class="bg-gray-100 px-1">make https</code>
                    @endif
                </li>
            </ul>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            @foreach ([
                'Источников подключено' => $stats['sources'],
                'Из них включено'       => $stats['enabled'],
                'Документов в базе'     => $stats['documents'],
                'Фрагментов для поиска' => $stats['chunks'],
                'Ждут индексации'       => $stats['pending'],
                'Ошибок индексации'     => $stats['failed'],
                'Пользователей'         => $stats['users'],
                'Вопросов задано'       => $stats['messages'],
                'Файлов приложено'      => $stats['files'],
            ] as $label => $value)
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <div class="text-2xl font-semibold text-gray-900">{{ $value }}</div>
                    <div class="text-xs text-gray-500 mt-1">{{ $label }}</div>
                </div>
            @endforeach
        </div>

        @if ($problems->isNotEmpty())
            <div class="rounded-lg border border-red-200 bg-red-50 p-5">
                <h2 class="font-medium text-red-900 mb-2">Источники с ошибками</h2>

                <ul class="space-y-2 text-sm text-red-800">
                    @foreach ($problems as $source)
                        <li>
                            <a href="{{ route('admin.sources.edit', $source) }}" class="underline font-medium">
                                {{ $source->name }}
                            </a>
                            — {{ $source->last_error }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-app-layout>
