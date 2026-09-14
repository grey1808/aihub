<?php

namespace Tests\Feature;

use App\Knowledge\SourceSynchronizer;
use App\Models\KnowledgeSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SourceSyncTest extends TestCase
{
    use RefreshDatabase;

    private function folderSource(string $path): KnowledgeSource
    {
        return KnowledgeSource::create([
            'name'                  => 'Документы',
            'type'                  => 'local_files',
            'is_enabled'            => true,
            'sync_interval_minutes' => 60,
            'config'                => ['path' => $path, 'recursive' => true],
        ]);
    }

    private function makeFolder(): string
    {
        $path = sys_get_temp_dir().'/aihub-test-'.uniqid();
        mkdir($path);
        file_put_contents($path.'/reglament.txt', 'Заявление на отпуск подаётся за 14 дней.');

        return $path;
    }

    public function test_после_синхронизации_состояние_источника_сохраняется(): void
    {
        // Поля состояния не были заполняемыми, и update() их молча отбрасывал.
        // Внешне всё работало: документы появлялись, а источник вечно
        // числился «не запускался».
        Http::fake(['*/embeddings' => Http::response([
            'data' => [['index' => 0, 'embedding' => array_fill(0, 384, 0.1)]],
        ])]);

        config(['llm.embedding_dimensions' => 384]);

        $path = $this->makeFolder();
        $source = $this->folderSource($path);

        app(SourceSynchronizer::class)->sync($source);

        $source->refresh();

        $this->assertSame('ok', $source->last_status);
        $this->assertNotNull($source->last_synced_at);
        $this->assertSame(1, $source->documents_count);

        unlink($path.'/reglament.txt');
        rmdir($path);
    }

    public function test_свежий_источник_не_переиндексируется_каждые_пять_минут(): void
    {
        // Пока last_synced_at оставался пустым, планировщик считал источник
        // просроченным всегда и запускал индексацию без остановки.
        $source = $this->folderSource('/tmp');

        $this->assertTrue($source->isDueForSync(), 'новый источник должен обновиться сразу');

        $source->update(['last_synced_at' => now()]);

        $this->assertFalse($source->fresh()->isDueForSync(), 'только что обновлённый — не должен');

        $source->update(['last_synced_at' => now()->subMinutes(61)]);

        $this->assertTrue($source->fresh()->isDueForSync(), 'через час после интервала — должен');
    }

    public function test_ошибка_синхронизации_попадает_в_карточку(): void
    {
        $source = $this->folderSource('/такой/папки/нет');

        app(SourceSynchronizer::class)->sync($source);

        $source->refresh();

        $this->assertSame('error', $source->last_status);
        $this->assertNotEmpty($source->last_error);
    }

    public function test_ручное_обновление_отключается_нулевым_интервалом(): void
    {
        $source = $this->folderSource('/tmp');
        $source->update(['sync_interval_minutes' => 0, 'last_synced_at' => now()->subYear()]);

        $this->assertFalse($source->fresh()->isDueForSync());
    }
}
