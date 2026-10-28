<?php

namespace Tests\Unit;

use App\Knowledge\TextExtractor;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

class SpreadsheetExtractionTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kp').'.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_из_таблицы_достаются_все_листы_с_именами(): void
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->setTitle('КП Стройбаза')->fromArray([
            ['Наименование', 'Ед.', 'Кол-во', 'Цена'],
            ['Бетон В25', 'м3', 120, 5400],
        ]);
        $book->createSheet()->setTitle('Условия')->setCellValue('A1', 'Доставка за счёт поставщика');

        $text = $this->extract($book);

        $this->assertStringContainsString('Лист «КП Стройбаза»', $text);
        $this->assertStringContainsString('Бетон В25 | м3 | 120 | 5400', $text);
        $this->assertStringContainsString('Лист «Условия»', $text);
        $this->assertStringContainsString('Доставка за счёт поставщика', $text);
    }

    public function test_формула_даёт_сохранённое_значение_а_не_текст_формулы(): void
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([['Бетон В25', 120, 5400, '=B1*C1']]);

        $text = $this->extract($book);

        $this->assertStringContainsString('648000', $text);
        $this->assertStringNotContainsString('=B1*C1', $text);
    }

    public function test_дата_выводится_как_дата_а_не_число(): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', 'Срок поставки');
        $sheet->setCellValue('B1', Date::PHPToExcel(new \DateTime('2026-11-15')));
        $sheet->getStyle('B1')->getNumberFormat()->setFormatCode('dd.mm.yyyy');

        $this->assertStringContainsString('Срок поставки | 15.11.2026', $this->extract($book));
    }

    public function test_пустой_лист_в_текст_не_попадает(): void
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->setTitle('Пустой');
        $book->createSheet()->setTitle('Данные')->setCellValue('A1', 'Арматура А500');

        $text = $this->extract($book);

        $this->assertStringNotContainsString('Пустой', $text);
        $this->assertStringContainsString('Арматура А500', $text);
    }

    private function extract(Spreadsheet $book): string
    {
        (new Xlsx($book))->save($this->path);

        return (string) (new TextExtractor())->extract($this->path);
    }
}
