<div class="mb-6 flex flex-wrap gap-2 text-sm">
    @php($tabs = [
        'admin.dashboard'     => 'Состояние системы',
        'admin.sources.index' => 'Источники знаний',
        'admin.settings.edit' => 'Поведение помощника',
        'admin.users.index'   => 'Пользователи',
    ])

    @foreach ($tabs as $route => $label)
        @php($group = str_replace(['.index', '.edit'], '', $route))
        <a href="{{ route($route) }}"
           class="rounded-md px-3 py-1.5 border {{ request()->routeIs($group) || request()->routeIs($group.'.*') ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50' }}">
            {{ $label }}
        </a>
    @endforeach
</div>
