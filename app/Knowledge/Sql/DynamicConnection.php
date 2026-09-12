<?php

namespace App\Knowledge\Sql;

use App\Models\KnowledgeSource;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Подключение к чужой базе (1С и любой другой), параметры которой
 * админ ввёл в форме. Регистрируем соединение на лету, чтобы не держать
 * список баз в config/database.php — их заранее неизвестно сколько.
 */
class DynamicConnection
{
    public static function for(KnowledgeSource $source): Connection
    {
        $config = $source->plainConfig();
        $name = 'source_'.$source->id;

        Config::set("database.connections.{$name}", array_filter([
            'driver'   => $config['driver'] ?? 'pgsql',
            'host'     => $config['host'] ?? '127.0.0.1',
            'port'     => $config['port'] ?? null,
            'database' => $config['database'] ?? '',
            'username' => $config['username'] ?? '',
            'password' => $config['password'] ?? '',
            'charset'  => ($config['driver'] ?? 'pgsql') === 'pgsql' ? 'utf8' : 'utf8',
            'prefix'   => '',
            // 1С на MS SQL часто стоит со старым сертификатом — иначе драйвер
            // откажется соединяться, а причину напишет невнятно.
            'encrypt'                  => 'no',
            'trust_server_certificate' => 'true',
            'options' => [
                \PDO::ATTR_TIMEOUT => 30,
            ],
        ], fn ($v) => $v !== null && $v !== ''));

        DB::purge($name);

        return DB::connection($name);
    }
}
