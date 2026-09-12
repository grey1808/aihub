<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRun extends Model
{
    protected $fillable = [
        'knowledge_source_id', 'status', 'found', 'created', 'updated',
        'skipped', 'deleted', 'message', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'knowledge_source_id');
    }
}
