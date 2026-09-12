<?php

namespace Tests\Unit;

use App\Knowledge\Sql\SqlGuard;
use PHPUnit\Framework\TestCase;

class SqlGuardTest extends TestCase
{
    public function test_обычный_select_проходит(): void
    {
        SqlGuard::assertReadOnly('SELECT _Description FROM _Reference123');

        $this->expectNotToPerformAssertions();
    }

    public function test_запросы_с_изменением_данных_отклоняются(): void
    {
        foreach ([
            'DELETE FROM _Reference123',
            'UPDATE _Reference123 SET _Description = 1',
            'DROP TABLE _Reference123',
            'INSERT INTO t VALUES (1)',
            'SELECT * FROM t; DROP TABLE t',
            'SELECT * INTO backup FROM t',
        ] as $sql) {
            try {
                SqlGuard::assertReadOnly($sql);
                $this->fail("Запрос должен был быть отклонён: {$sql}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_опасное_слово_в_комментарии_не_обманывает_проверку(): void
    {
        // Комментарии вырезаются до проверки, поэтому безобидный текст
        // не должен блокировать нормальный запрос.
        SqlGuard::assertReadOnly("SELECT a FROM t -- здесь мог быть drop\n");

        $this->expectNotToPerformAssertions();
    }

    public function test_ограничение_строк_дописывается(): void
    {
        $this->assertSame(
            'SELECT a FROM t LIMIT 100',
            SqlGuard::withLimit('SELECT a FROM t', 100, 'pgsql')
        );

        $this->assertSame(
            'SELECT TOP (100) a FROM t',
            SqlGuard::withLimit('SELECT a FROM t', 100, 'sqlsrv')
        );
    }

    public function test_чужое_ограничение_не_ломается(): void
    {
        $this->assertSame(
            'SELECT a FROM t LIMIT 5',
            SqlGuard::withLimit('SELECT a FROM t LIMIT 5', 100, 'pgsql')
        );
    }
}
