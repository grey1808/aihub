<?php

namespace App\Models;

use App\Knowledge\ConnectorRegistry;
use App\Knowledge\Contracts\Connector;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class KnowledgeSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'type', 'description', 'is_enabled', 'config', 'sync_interval_minutes',

        // Поля состояния тоже заполняемые: их пишет синхронизатор через
        // update(), и без этого они молча отбрасывались. Последствие было
        // тяжёлым: last_synced_at оставался пустым, источник считался
        // просроченным всегда, и планировщик переиндексировал его каждые
        // пять минут без остановки.
        'last_synced_at', 'last_status', 'last_error', 'documents_count',
    ];

    protected $casts = [
        'is_enabled'      => 'boolean',
        'config'          => 'array',
        'last_synced_at'  => 'datetime',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class)->latest('id');
    }

    public function connector(): Connector
    {
        return app(ConnectorRegistry::class)->make($this);
    }

    /**
     * Пароли и токены не должны лежать в базе открытым текстом.
     * Коннектор сам говорит, какие его поля секретные — их шифруем,
     * а остальное оставляем читаемым, чтобы было удобно смотреть в админке.
     */
    public function setConfigAttribute(?array $value): void
    {
        $value ??= [];
        $secret = app(ConnectorRegistry::class)->secretFields($this->type);

        foreach ($secret as $field) {
            if (isset($value[$field]) && $value[$field] !== '') {
                $value[$field] = Crypt::encryptString((string) $value[$field]);
            }
        }

        $this->attributes['config'] = json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /** Расшифрованный конфиг — то, с чем работает коннектор. */
    public function plainConfig(): array
    {
        $config = $this->config ?? [];
        $secret = app(ConnectorRegistry::class)->secretFields($this->type);

        foreach ($secret as $field) {
            if (! empty($config[$field])) {
                try {
                    $config[$field] = Crypt::decryptString($config[$field]);
                } catch (\Throwable) {
                    // Значение записали до включения шифрования — оставляем как есть.
                }
            }
        }

        return $config;
    }

    public function isDueForSync(): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        if ($this->sync_interval_minutes <= 0) {
            return false; // 0 = только вручную
        }

        return $this->last_synced_at === null
            || $this->last_synced_at->addMinutes($this->sync_interval_minutes)->isPast();
    }
}
