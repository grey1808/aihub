<?php

namespace Tests\Unit;

use App\Rag\Chunker;
use PHPUnit\Framework\TestCase;

class ChunkerTest extends TestCase
{
    public function test_короткий_текст_остаётся_одним_куском(): void
    {
        $chunks = (new Chunker(500, 50))->split('Короткий регламент в одну строку.');

        $this->assertCount(1, $chunks);
    }

    public function test_длинный_текст_режется_на_куски_нужного_размера(): void
    {
        $paragraph = str_repeat('Текст регламента. ', 30);
        $chunks = (new Chunker(300, 50))->split(implode("\n\n", array_fill(0, 6, $paragraph)));

        $this->assertGreaterThan(1, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(320, mb_strlen($chunk));
        }
    }

    public function test_предложение_длиннее_куска_не_зацикливает_разбиение(): void
    {
        // Таблицы и выгрузки бывают без единого знака препинания —
        // раньше на таком тексте разбиение уходило в бесконечность.
        $chunks = (new Chunker(100, 10))->split(str_repeat('а', 1000));

        $this->assertGreaterThan(5, count($chunks));
        $this->assertLessThan(20, count($chunks));
    }

    public function test_пустой_текст_даёт_пустой_результат(): void
    {
        $this->assertSame([], (new Chunker(100, 10))->split("   \n\n  "));
    }
}
