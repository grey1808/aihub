<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="'Почта или логин'" />
            <x-text-input id="email" class="block mt-1 w-full" type="text" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" name="remember">
                <span class="ms-2 text-sm text-gray-600">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">
            @if (Route::has('password.request'))
                <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" href="{{ route('password.request') }}">
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-primary-button class="ms-3">
                Войти
            </x-primary-button>
        </div>
    </form>

    {{-- Регистрация открыта: сотрудник заводит себе учётную запись сам,
         поэтому ссылка должна быть на виду, а не где-то по прямому адресу. --}}
    <div class="mt-6 border-t border-gray-200 pt-6 text-center">
        <p class="text-sm text-gray-600">Первый раз здесь?</p>

        <a href="{{ route('register') }}"
           class="mt-2 inline-flex w-full items-center justify-center rounded-md border border-indigo-600 px-4 py-2.5 text-sm font-medium text-indigo-600 hover:bg-indigo-50">
            Зарегистрироваться
        </a>
    </div>
</x-guest-layout>
