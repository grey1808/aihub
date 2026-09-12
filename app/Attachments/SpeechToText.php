<?php

namespace App\Attachments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Расшифровка голосовых сообщений.
 *
 * Ollama распознавать речь не умеет — это отдельная задача и отдельная
 * модель (Whisper). Поэтому рядом поднимается маленький сервис, который
 * говорит по тому же протоколу, что и OpenAI: POST /audio/transcriptions.
 */
class SpeechToText
{
    public function enabled(): bool
    {
        return (bool) config('attachments.stt.enabled');
    }

    public function transcribe(string $path, string $filename): string
    {
        if (! $this->enabled()) {
            throw new \RuntimeException(
                'Распознавание речи выключено. Включите сервис whisper: '
                .'docker compose --profile with-whisper up -d'
            );
        }

        $response = Http::baseUrl(rtrim((string) config('attachments.stt.base_url'), '/'))
            ->timeout((int) config('attachments.stt.timeout'))
            ->connectTimeout(10)
            ->attach('file', file_get_contents($path), $filename)
            ->post('/audio/transcriptions', [
                'model'    => config('attachments.stt.model'),
                'language' => config('attachments.stt.language'),
                // Формат просим текстом: json у разных сборок Whisper
                // называет поле по-разному, а plain text — везде одинаково.
                'response_format' => 'text',
            ]);

        if ($response->failed()) {
            Log::error('Распознавание речи не удалось', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new \RuntimeException(
                'Сервис распознавания речи ответил ошибкой '.$response->status().'. '
                .'Проверьте, запущен ли контейнер whisper.'
            );
        }

        $text = trim($response->body());

        // На всякий случай: если сборка всё же вернула json, достаём поле text.
        if (str_starts_with($text, '{')) {
            $text = trim((string) (json_decode($text, true)['text'] ?? $text));
        }

        return $text;
    }

    public function ping(): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'error' => 'выключено в настройках'];
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('attachments.stt.base_url'), '/'))
                ->timeout(10)
                ->get('/models');

            return $response->successful()
                ? ['ok' => true, 'base_url' => config('attachments.stt.base_url')]
                : ['ok' => false, 'error' => 'ответ '.$response->status()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
