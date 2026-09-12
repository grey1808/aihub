<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends Model
{
    protected $fillable = [
        'document_id', 'knowledge_source_id', 'chunk_index', 'content',
    ];

    // Колонку embedding через Eloquent не пишем: тип vector Postgres
    // требует своего синтаксиса, поэтому вставка идёт сырым SQL в Indexer.
    protected $guarded = ['embedding'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
