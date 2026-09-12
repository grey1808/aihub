<x-app-layout>
    <div class="max-w-3xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        @include('admin.partials.nav')
        <x-flash />

        <h1 class="text-lg font-medium text-gray-900 mb-4">Новый источник знаний</h1>

        @if (! $type)
            <p class="text-sm text-gray-600 mb-4">Выберите, откуда помощник будет брать информацию.</p>

            <div class="space-y-3">
                @foreach ($registry->options() as $key => $option)
                    <a href="{{ route('admin.sources.create', ['type' => $key]) }}"
                       class="block rounded-lg border border-gray-200 bg-white p-4 hover:border-indigo-400 hover:bg-indigo-50/30">
                        <div class="font-medium text-gray-900">{{ $option['label'] }}</div>
                        <div class="mt-1 text-sm text-gray-500">{{ $option['help'] }}</div>
                    </a>
                @endforeach
            </div>
        @else
            <form method="POST" action="{{ route('admin.sources.store') }}"
                  class="rounded-lg border border-gray-200 bg-white p-6">
                @csrf
                <input type="hidden" name="type" value="{{ $type }}">

                <p class="mb-5 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-600">
                    {{ $registry->class($type)::label() }} — {{ $registry->class($type)::help() }}
                </p>

                @include('admin.sources.form', ['registry' => $registry, 'type' => $type, 'values' => []])

                <div class="mt-6 flex gap-3">
                    <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        Создать
                    </button>

                    <a href="{{ route('admin.sources.create') }}" class="px-4 py-2 text-sm text-gray-600 hover:underline">
                        Выбрать другой тип
                    </a>
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
