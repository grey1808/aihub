<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        {{-- viewport-fit=cover нужен, чтобы поле ввода не пряталось
             под «чёлкой» и полосой жестов на телефонах. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#4f46e5">

        <title>{{ config('app.name', 'Помощник') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/chat.js'])
    </head>

    {{-- 100dvh, а не 100vh: на телефоне высота окна меняется, когда
         появляется и исчезает панель браузера, и на 100vh поле ввода
         уезжает под край экрана. --}}
    <body class="h-full overflow-hidden bg-gray-100 font-sans antialiased">
        <div class="flex h-[100dvh] flex-col">
            {{ $slot }}
        </div>

        @stack('scripts')
    </body>
</html>
