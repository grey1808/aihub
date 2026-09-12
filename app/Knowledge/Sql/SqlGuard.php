<?php

namespace App\Knowledge\Sql;

/**
 * Страж SQL-запросов.
 *
 * Агент ходит в базу 1С только на чтение. Пока заказчик не попросил обратное,
 * любая попытка что-то изменить должна отбиваться здесь, а не надеяться
 * на права пользователя в СУБД. Права в СУБД — вторая линия обороны,
 * выдавать агенту read-only учётку всё равно обязательно.
 */
class SqlGuard
{
    private const FORBIDDEN = [
        'insert', 'update', 'delete', 'drop', 'truncate', 'alter', 'create',
        'grant', 'revoke', 'merge', 'exec', 'execute', 'call', 'into',
        'xp_cmdshell', 'sp_executesql', 'copy', 'commit', 'rollback',
    ];

    public static function assertReadOnly(string $sql): void
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', self::stripComments($sql))));
        $normalized = rtrim($normalized, "; \t\n");

        if ($normalized === '') {
            throw new \InvalidArgumentException('Пустой запрос.');
        }

        if (str_contains($normalized, ';')) {
            throw new \InvalidArgumentException('Можно выполнить только один запрос за раз.');
        }

        if (! preg_match('/^(select|with)\b/', $normalized)) {
            throw new \InvalidArgumentException('Разрешены только запросы SELECT.');
        }

        foreach (self::FORBIDDEN as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/', $normalized)) {
                throw new \InvalidArgumentException("Запрос отклонён: в нём есть «{$word}», а доступ только на чтение.");
            }
        }
    }

    /** Дописываем ограничение строк, если его не поставили. */
    public static function withLimit(string $sql, int $limit, string $driver): string
    {
        $sql = rtrim(trim(self::stripComments($sql)), "; \t\n");

        if ($driver === 'sqlsrv') {
            return preg_match('/\btop\s*\(/i', $sql) || preg_match('/\bfetch\s+next\b/i', $sql)
                ? $sql
                : preg_replace('/^\s*select\s+/i', "SELECT TOP ({$limit}) ", $sql, 1);
        }

        return preg_match('/\blimit\s+\d+/i', $sql) ? $sql : $sql." LIMIT {$limit}";
    }

    private static function stripComments(string $sql): string
    {
        $sql = preg_replace('/--[^\n]*/', ' ', $sql) ?? $sql;

        return preg_replace('#/\*.*?\*/#s', ' ', $sql) ?? $sql;
    }
}
