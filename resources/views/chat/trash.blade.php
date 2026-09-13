<x-chat-layout>
    @include('chat.partials.header')

    <div class="flex min-h-0 flex-1">
        <div data-drawer-overlay class="fixed inset-0 z-40 hidden bg-black/40 md:hidden"></div>

        <aside data-drawer
               class="fixed inset-y-0 left-0 z-50 flex w-72 max-w-[85vw] -translate-x-full flex-col border-r border-gray-200 bg-white transition-transform duration-200 md:static md:z-auto md:w-72 md:translate-x-0">
            @include('chat.partials.sidebar')
        </aside>

        {{-- Разделитель: тянется мышью, ширина запоминается в браузере.
             Двойной щелчок возвращает ширину по умолчанию. --}}
        <div data-resizer
             class="hidden w-1 shrink-0 cursor-col-resize bg-gray-200 transition-colors hover:bg-indigo-400 md:block"
             title="Потяните, чтобы изменить ширину. Двойной щелчок — вернуть как было"></div>

        <section class="min-h-0 flex-1 overflow-y-auto bg-gray-50 p-4">
            @if (session('status'))
                <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            <div class="mx-auto max-w-3xl">
                <h1 class="text-lg font-medium text-gray-900">Корзина</h1>
                <p class="mt-1 text-sm text-gray-600">
                    Удалённые чаты хранятся {{ $days }} дней, потом исчезают сами вместе
                    с сообщениями и приложенными файлами.
                </p>

                @if ($deleted->isEmpty())
                    <p class="mt-6 rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500">
                        Корзина пуста.
                    </p>
                @else
                    <div class="mt-4 divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 bg-white">
                        @foreach ($deleted as $item)
                            <div class="flex flex-wrap items-center gap-3 p-4">
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium text-gray-900">{{ $item->title }}</div>
                                    <div class="text-xs text-gray-500">
                                        Удалён {{ $item->deleted_at->diffForHumans() }} ·
                                        исчезнет {{ $item->deleted_at->addDays($days)->format('d.m.Y') }}
                                    </div>
                                </div>

                                <form method="POST" action="{{ route('chat.restore', $item->id) }}">
                                    @csrf
                                    <button class="rounded-md border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50">
                                        Вернуть
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('chat.force-destroy', $item->id) }}"
                                      onsubmit="return confirm('Удалить «{{ $item->title }}» окончательно? Вернуть будет нельзя.')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="rounded-md border border-red-200 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50">
                                        Удалить навсегда
                                    </button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    </div>
</x-chat-layout>
