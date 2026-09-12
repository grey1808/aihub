<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Проект — папка для чатов на одну тему.
 *
 * Кроме группировки даёт три вещи, ради которых всё и затевалось:
 * общую инструкцию помощнику, общую память по теме и ограничение поиска
 * нужными источниками. Сами чаты друг друга не видят — почему именно так,
 * написано в README.
 */
class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'name', 'description', 'instructions', 'notes', 'notes_updated_at', 'source_ids',
    ];

    protected $casts = [
        'source_ids'       => 'array',
        'notes_updated_at' => 'datetime',
        'last_used_at'     => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(ChatThread::class)->latest('last_message_at');
    }

    /** Источники, по которым разрешено искать. Пустой список — искать везде. */
    public function sourceIds(): array
    {
        return array_values(array_filter(array_map('intval', $this->source_ids ?? [])));
    }

    public function sources()
    {
        $ids = $this->sourceIds();

        return $ids === []
            ? KnowledgeSource::where('is_enabled', true)->get()
            : KnowledgeSource::whereIn('id', $ids)->get();
    }

    public function rememberNote(string $text): void
    {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        $this->update([
            'notes'            => trim(trim((string) $this->notes)."\n- ".$text." _(записано ".now()->format('d.m.Y').")_"),
            'notes_updated_at' => now(),
        ]);
    }

    public function touchUsage(): void
    {
        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }
}
