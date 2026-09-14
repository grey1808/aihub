<?php

namespace Tests\Feature;

use App\Models\KnowledgeSource;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DatabaseDriversTest extends TestCase
{
    use RefreshDatabase;

    public function test_без_драйвера_ms_sql_источник_объясняет_причину_понятно(): void
    {
        if (in_array('sqlsrv', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('Драйвер MS SQL в этой сборке есть — проверять нечего.');
        }

        $source = KnowledgeSource::create([
            'name'   => 'База 1С',
            'type'   => 'onec_sql',
            'config' => ['driver' => 'sqlsrv', 'host' => 'server', 'database' => 'buh'],
        ]);

        $result = $source->connector()->test();

        $this->assertFalse($result['ok']);
        // Сообщение от самого PHP («could not find driver») отправляет админа
        // искать проблему в логине и пароле — а дело совсем не в них.
        $this->assertStringContainsString('OData', $result['message']);
    }

    public function test_postgresql_доступен_всегда(): void
    {
        // Без него не работает само приложение, не только подключение к 1С.
        $this->assertContains('pgsql', \PDO::getAvailableDrivers());
    }

    public function test_клиент_redis_выбирается_по_наличию_расширения(): void
    {
        $expected = extension_loaded('redis') ? 'phpredis' : 'predis';

        $this->assertSame($expected, config('database.redis.client'));
    }

    public function test_кэш_работает_на_выбранном_клиенте(): void
    {
        $store = config('cache.default');

        if ($store !== 'redis') {
            $this->markTestSkipped('В тестах кэш не на redis.');
        }

        cache()->store('redis')->put('proverka', 'ok', 10);

        $this->assertSame('ok', cache()->store('redis')->get('proverka'));
    }
}
