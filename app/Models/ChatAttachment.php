<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ChatAttachment extends Model
{
    protected $fillable = [
        'chat_message_id', 'chat_thread_id', 'user_id', 'kind', 'original_name',
        'path', 'mime', 'size', 'extracted_text', 'status', 'error', 'duration_ms',
    ];

    public const KIND_IMAGE = 'image';
    public const KIND_AUDIO = 'audio';
    public const KIND_DOCUMENT = 'document';

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'chat_message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function disk()
    {
        return Storage::disk('attachments');
    }

    public function exists(): bool
    {
        return $this->disk()->exists($this->path);
    }

    public function contents(): string
    {
        return (string) $this->disk()->get($this->path);
    }

    /** Картинка для модели передаётся строкой data:image/...;base64,... */
    public function asDataUrl(): string
    {
        return 'data:'.($this->mime ?: 'application/octet-stream').';base64,'.base64_encode($this->contents());
    }

    public function humanSize(): string
    {
        $size = (int) $this->size;

        return match (true) {
            $size >= 1048576 => round($size / 1048576, 1).' МБ',
            $size >= 1024    => round($size / 1024).' КБ',
            default          => $size.' Б',
        };
    }

    public function icon(): string
    {
        return match ($this->kind) {
            self::KIND_IMAGE => '🖼',
            self::KIND_AUDIO => '🎤',
            default          => '📄',
        };
    }

    protected static function booted(): void
    {
        // Запись исчезла — файл тоже не должен оставаться на диске.
        static::deleting(function (self $attachment) {
            if ($attachment->exists()) {
                $attachment->disk()->delete($attachment->path);
            }
        });
    }
}
