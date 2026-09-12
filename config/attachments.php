<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Вложения в чате
    |--------------------------------------------------------------------------
    */

    // Максимальный размер одного файла. Больше 64 МБ поднимать нельзя,
    // не подняв заодно upload_max_filesize в docker/php/php.ini
    // и client_max_body_size в docker/nginx/default.conf.
    'max_size' => (int) env('ATTACHMENT_MAX_SIZE', 33554432), // 32 МБ

    // Сколько файлов можно приложить к одному сообщению.
    'max_per_message' => (int) env('ATTACHMENT_MAX_PER_MESSAGE', 5),

    // Сколько символов из документа отдаём модели. Договор на 80 страниц
    // целиком не влезет в контекст локальной модели — берём начало.
    'max_text_chars' => (int) env('ATTACHMENT_MAX_TEXT_CHARS', 12000),

    // Разрешённые расширения по типам.
    'images'    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif'],
    'audio'     => ['mp3', 'wav', 'ogg', 'oga', 'opus', 'webm', 'm4a', 'aac', 'flac', 'amr', 'mp4'],
    'documents' => ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'md', 'csv', 'html', 'htm', 'xml', 'json'],

    // Через сколько часов удалять файлы, которые загрузили,
    // но так и не отправили сообщением.
    'orphan_lifetime_hours' => (int) env('ATTACHMENT_ORPHAN_LIFETIME_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Распознавание речи
    |--------------------------------------------------------------------------
    | Отдельный сервис с моделью Whisper. Говорит по тому же протоколу,
    | что и OpenAI: POST /audio/transcriptions.
    */

    'stt' => [
        'enabled'  => (bool) env('STT_ENABLED', true),
        'base_url' => env('STT_BASE_URL', 'http://whisper:8000/v1'),
        'model'    => env('STT_MODEL', 'Systran/faster-whisper-small'),
        'language' => env('STT_LANGUAGE', 'ru'),
        'timeout'  => (int) env('STT_TIMEOUT', 300),
    ],

];
