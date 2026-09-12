<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_thread_id', 'role', 'content', 'sources', 'tool_calls', 'tokens', 'duration_ms',
    ];

    protected $casts = [
        'sources'    => 'array',
        'tool_calls' => 'array',
    ];

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatAttachment::class)->orderBy('id');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'chat_thread_id');
    }
}
