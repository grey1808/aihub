{{-- Общие поля формы пользователя: используются и при создании, и при правке --}}
@php($user = $user ?? null)

<div class="grid gap-5 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label for="name" class="block text-sm font-medium text-gray-800">
            Имя и фамилия <span class="text-red-500">*</span>
        </label>
        <input type="text" name="name" id="name" required maxlength="255"
               value="{{ old('name', $user->name ?? '') }}"
               class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
    </div>

    <div>
        <label for="preferred_name" class="block text-sm font-medium text-gray-800">Как обращаться</label>
        <input type="text" name="preferred_name" id="preferred_name" maxlength="120"
               value="{{ old('preferred_name', $user->preferred_name ?? '') }}"
               placeholder="Ольга Петровна"
               class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        <p class="mt-1 text-xs text-gray-500">Необязательно.</p>
    </div>

    <div>
        <label for="position" class="block text-sm font-medium text-gray-800">Должность</label>
        <input type="text" name="position" id="position" maxlength="160"
               value="{{ old('position', $user->position ?? '') }}"
               placeholder="Бухгалтер"
               class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        <p class="mt-1 text-xs text-gray-500">Помощник учитывает её в ответах.</p>
    </div>

    <div>
        <label for="department" class="block text-sm font-medium text-gray-800">Отдел</label>
        <input type="text" name="department" id="department" maxlength="160"
               value="{{ old('department', $user->department ?? '') }}"
               placeholder="Бухгалтерия"
               class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
    </div>

    <div>
        <label for="email" class="block text-sm font-medium text-gray-800">
            Почта <span class="text-red-500">*</span>
        </label>
        <input type="email" name="email" id="email" required maxlength="255"
               value="{{ old('email', $user->email ?? '') }}"
               class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        <p class="mt-1 text-xs text-gray-500">По ней сотрудник входит.</p>
    </div>

    <div class="sm:col-span-2">
        <label for="login" class="block text-sm font-medium text-gray-800">Короткий логин</label>
        <input type="text" name="login" id="login" maxlength="60"
               value="{{ old('login', $user->login ?? '') }}"
               placeholder="obuh"
               class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        <p class="mt-1 text-xs text-gray-500">
            Необязательно. Если задан, войти можно и по нему — удобнее, чем диктовать почту.
            Латиница, цифры, дефис.
        </p>
    </div>
</div>
