<?php

namespace App\Attachments;

use App\Knowledge\TextExtractor;
use App\Models\ChatAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Приём и разбор вложений.
 *
 * Задача одна: превратить любой приложенный файл в то, с чем модель
 * умеет работать. Документ — в текст, голос — в расшифровку, картинку —
 * в data-url для модели со зрением.
 */
class AttachmentProcessor
{
    public function __construct(
        private readonly TextExtractor $extractor,
        private readonly SpeechToText $speech,
    ) {
    }

    /** Определить, что за файл нам дали. */
    public function kindOf(string $extension, ?string $mime = null): ?string
    {
        $extension = strtolower(ltrim($extension, '.'));

        foreach ([
            ChatAttachment::KIND_IMAGE    => config('attachments.images'),
            ChatAttachment::KIND_AUDIO    => config('attachments.audio'),
            ChatAttachment::KIND_DOCUMENT => config('attachments.documents'),
        ] as $kind => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $kind;
            }
        }

        // Диктофон телефона иногда отдаёт файл без внятного расширения —
        // тогда ориентируемся на mime-тип из браузера.
        return match (true) {
            $mime && str_starts_with($mime, 'image/') => ChatAttachment::KIND_IMAGE,
            $mime && str_starts_with($mime, 'audio/') => ChatAttachment::KIND_AUDIO,
            $mime && str_starts_with($mime, 'video/') => ChatAttachment::KIND_AUDIO, // запись с телефона бывает в video/webm
            default => null,
        };
    }

    public function store(UploadedFile $file, int $userId, ?int $threadId): ChatAttachment
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: '');
        $kind = $this->kindOf($extension, $file->getMimeType());

        if (! $kind) {
            throw new \InvalidArgumentException(
                'Такой тип файла не поддерживается. Можно: документы, картинки и звук.'
            );
        }

        $name = Str::random(24).($extension ? '.'.$extension : '');
        $path = $userId.'/'.$name;

        $attachment = ChatAttachment::create([
            'chat_thread_id' => $threadId,
            'user_id'        => $userId,
            'kind'           => $kind,
            'original_name'  => $this->safeName($file->getClientOriginalName() ?: $name),
            'path'           => $path,
            'mime'           => $file->getMimeType(),
            'size'           => $file->getSize(),
            'status'         => 'pending',
        ]);

        $attachment->disk()->put($path, file_get_contents($file->getRealPath()));

        return $this->process($attachment);
    }

    /**
     * Разбор файла. Голос расшифровываем сразу при загрузке, а не при
     * отправке: пользователь успевает увидеть текст и поправить его,
     * прежде чем сообщение уйдёт модели.
     */
    public function process(ChatAttachment $attachment): ChatAttachment
    {
        $startedAt = microtime(true);
        $localPath = $attachment->disk()->path($attachment->path);

        try {
            $text = match ($attachment->kind) {
                ChatAttachment::KIND_AUDIO    => $this->speech->transcribe($localPath, $attachment->original_name),
                ChatAttachment::KIND_DOCUMENT => $this->extractor->extract($localPath, $attachment->original_name),
                default                       => null,
            };

            if ($attachment->kind === ChatAttachment::KIND_DOCUMENT && ! $text) {
                throw new \RuntimeException('Не удалось прочитать текст из этого файла.');
            }

            if ($attachment->kind === ChatAttachment::KIND_AUDIO && trim((string) $text) === '') {
                throw new \RuntimeException('В записи не распозналось ни слова. Попробуйте записать заново.');
            }

            $attachment->update([
                'extracted_text' => $text ? Str::limit($text, (int) config('attachments.max_text_chars'), '… (текст обрезан)') : null,
                'status'         => 'ready',
                'error'          => null,
                'duration_ms'    => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Вложение не разобралось', [
                'attachment' => $attachment->id,
                'kind'       => $attachment->kind,
                'error'      => $e->getMessage(),
            ]);

            $attachment->update([
                'status'      => 'failed',
                'error'       => $e->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
        }

        return $attachment->refresh();
    }

    /**
     * Текстовая часть вложений для модели.
     *
     * Картинки сюда не попадают — они уходят отдельным каналом,
     * как изображения, см. LlmClient::chat().
     */
    public function textContext(iterable $attachments): string
    {
        $blocks = [];

        foreach ($attachments as $attachment) {
            if ($attachment->kind === ChatAttachment::KIND_IMAGE) {
                continue;
            }

            if ($attachment->status !== 'ready' || ! $attachment->extracted_text) {
                $blocks[] = "Файл «{$attachment->original_name}» приложен, но прочитать его не удалось"
                          .($attachment->error ? ": {$attachment->error}" : '.');

                continue;
            }

            $blocks[] = $attachment->kind === ChatAttachment::KIND_AUDIO
                ? "Расшифровка голосового сообщения «{$attachment->original_name}»:\n{$attachment->extracted_text}"
                : "Содержимое приложенного файла «{$attachment->original_name}»:\n{$attachment->extracted_text}";
        }

        return $blocks === [] ? '' : implode("\n\n---\n\n", $blocks);
    }

    /**
     * Текст вложений без служебных подписей — для поиска по базе знаний.
     *
     * В контекст модели подписи нужны («это расшифровка голосового»),
     * а в поисковом запросе они только мешают: ищется по ним, а не по сути.
     */
    public function searchableText(iterable $attachments): string
    {
        $parts = [];

        foreach ($attachments as $attachment) {
            if ($attachment->status === 'ready' && $attachment->extracted_text) {
                $parts[] = $attachment->extracted_text;
            }
        }

        return trim(implode("\n", $parts));
    }

    /** Имя файла чистим: оно попадёт в интерфейс и в промпт модели. */
    private function safeName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name;

        return Str::limit(trim(str_replace(['<', '>', '"'], '', $name)), 120, '');
    }
}
