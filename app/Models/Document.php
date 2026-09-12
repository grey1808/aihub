<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'knowledge_source_id', 'external_id', 'title', 'uri', 'mime',
        'content_hash', 'content', 'meta', 'index_status', 'index_error', 'source_updated_at',
    ];

    protected $casts = [
        'meta'              => 'array',
        'source_updated_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'knowledge_source_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }
}
