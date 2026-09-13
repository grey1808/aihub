{{-- Одно меню действий на всю боковую панель.
     Адреса форм подставляет JS, туда же попадает выбранный чат. --}}
<div data-chat-actions
     class="fixed z-[60] hidden w-64 rounded-lg border border-gray-200 bg-white py-1 shadow-xl">

    <form data-form="rename" method="POST" action="" class="border-b border-gray-100 p-3">
        @csrf
        @method('PATCH')

        <label class="block text-xs text-gray-500">Название</label>
        <div class="mt-1 flex gap-1">
            <input type="text" name="title" data-title required maxlength="120"
                   class="min-w-0 flex-1 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <button class="shrink-0 rounded-md bg-gray-800 px-3 text-sm text-white hover:bg-gray-900">ОК</button>
        </div>
    </form>

    <form data-form="move" method="POST" action="" class="border-b border-gray-100 p-3">
        @csrf
        @method('PATCH')

        <label class="block text-xs text-gray-500">Проект</label>
        <div class="mt-1 flex gap-1">
            <select name="project_id" data-project
                    class="min-w-0 flex-1 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">— без проекта —</option>
                @foreach ($projects as $project)
                    <option value="{{ $project->id }}">{{ $project->name }}</option>
                @endforeach
            </select>
            <button class="shrink-0 rounded-md bg-gray-800 px-3 text-sm text-white hover:bg-gray-900">ОК</button>
        </div>

        @if ($projects->isEmpty())
            <p class="mt-1 text-[11px] text-gray-400">Проектов пока нет — создайте в панели слева.</p>
        @endif
    </form>

    <form data-form="delete" method="POST" action="">
        @csrf
        @method('DELETE')
        <button class="block w-full px-4 py-2.5 text-left text-sm text-red-600 hover:bg-red-50">
            Удалить чат
        </button>
    </form>
</div>
