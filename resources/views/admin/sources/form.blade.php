@php($source = $source ?? null)

<div class="mb-5">
    <label for="name" class="block text-sm font-medium text-gray-800">Название <span class="text-red-500">*</span></label>
    <input type="text" name="name" id="name" required maxlength="120"
           value="{{ old('name', $source->name ?? '') }}"
           placeholder="Например: Регламенты бухгалтерии"
           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
    <p class="mt-1 text-xs text-gray-500">Как источник будет называться в списке и в ссылках под ответами помощника.</p>
</div>

<div class="mb-5">
    <label for="description" class="block text-sm font-medium text-gray-800">Что здесь лежит</label>
    <textarea name="description" id="description" rows="3"
              placeholder="Например: приказы и регламенты бухгалтерии, порядок оформления командировок и авансовых отчётов"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $source->description ?? '') }}</textarea>
    <p class="mt-1 text-xs text-gray-500">
        Это поле читает сам помощник — по нему он решает, куда идти за ответом.
        Опишите человеческими словами, какие вопросы закрывает этот источник.
    </p>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5">
    <div>
        <label for="sync_interval_minutes" class="block text-sm font-medium text-gray-800">Как часто обновлять, минут</label>
        <input type="number" name="sync_interval_minutes" id="sync_interval_minutes" min="0" max="100000"
               value="{{ old('sync_interval_minutes', $source->sync_interval_minutes ?? 60) }}"
               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
        <p class="mt-1 text-xs text-gray-500">0 — обновлять только вручную кнопкой.</p>
    </div>

    <div class="flex items-end pb-2">
        <label class="inline-flex items-center gap-2 text-sm text-gray-800">
            <input type="hidden" name="is_enabled" value="0">
            <input type="checkbox" name="is_enabled" value="1"
                   @checked(old('is_enabled', $source->is_enabled ?? true))
                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
            Источник включён
        </label>
    </div>
</div>

<hr class="my-6 border-gray-100">

@foreach ($registry->fields($type) as $field)
    @include('admin.partials.field', ['field' => $field, 'values' => $values])
@endforeach
