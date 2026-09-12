{{--
    Одно поле формы источника. Что рисовать, решает описание поля,
    которое отдал сам коннектор — поэтому новый тип источника появляется
    в админке без правки шаблонов.
--}}
@php
    $name    = $field['name'];
    $type    = $field['type'] ?? 'text';
    $key     = 'config.'.$name;
    $current = old($key, $values[$name] ?? ($field['default'] ?? null));
    $isSecret = ($field['secret'] ?? false) || $type === 'password';
@endphp

<div class="mb-5">
    <label for="{{ $key }}" class="block text-sm font-medium text-gray-800">
        {{ $field['label'] }}
        @if ($field['required'] ?? false)
            <span class="text-red-500">*</span>
        @endif
    </label>

    @if ($type === 'textarea')
        <textarea name="config[{{ $name }}]" id="{{ $key }}" rows="{{ $field['rows'] ?? 4 }}"
                  placeholder="{{ $field['placeholder'] ?? '' }}"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500">{{ $current }}</textarea>

    @elseif ($type === 'select')
        <select name="config[{{ $name }}]" id="{{ $key }}"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
            @foreach ($field['options'] as $value => $label)
                <option value="{{ $value }}" @selected((string) $current === (string) $value)>{{ $label }}</option>
            @endforeach
        </select>

    @elseif ($type === 'checkbox')
        <div class="mt-1">
            <input type="hidden" name="config[{{ $name }}]" value="0">
            <input type="checkbox" name="config[{{ $name }}]" id="{{ $key }}" value="1"
                   @checked((bool) $current)
                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
        </div>

    @else
        <input type="{{ $type === 'password' ? 'password' : ($type === 'number' ? 'number' : 'text') }}"
               name="config[{{ $name }}]" id="{{ $key }}"
               value="{{ $isSecret ? '' : $current }}"
               placeholder="{{ $field['placeholder'] ?? '' }}"
               autocomplete="off"
               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
    @endif

    @if (! empty($field['help']))
        <p class="mt-1 text-xs text-gray-500">{{ $field['help'] }}</p>
    @endif

    @if ($isSecret && ! empty($values[$name]))
        <p class="mt-1 text-xs text-gray-400">Значение сохранено. Оставьте поле пустым, чтобы не менять его.</p>
    @endif

    @error($key)
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
