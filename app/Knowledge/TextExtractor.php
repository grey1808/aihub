<?php

namespace App\Knowledge;

use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Достаёт текст из файла. Всё, что не смогли прочитать, пропускаем:
 * лучше не проиндексировать один файл, чем уронить всю синхронизацию.
 */
class TextExtractor
{
    public function extract(string $path, ?string $originalName = null): ?string
    {
        $extension = strtolower(pathinfo($originalName ?? $path, PATHINFO_EXTENSION));

        try {
            $text = match ($extension) {
                'pdf'                        => $this->fromPdf($path),
                'doc', 'docx', 'odt', 'rtf'  => $this->fromWord($path, $extension),
                'html', 'htm', 'xml'         => $this->fromHtml(file_get_contents($path)),
                'csv'                        => $this->fromCsv($path),
                'json'                       => $this->fromJson($path),
                'txt', 'md'                  => file_get_contents($path),
                default                      => null,
            };
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return $this->normalize((string) $text);
    }

    public function fromHtml(?string $html): string
    {
        if (! $html) {
            return '';
        }

        // Скрипты и стили выкидываем целиком, иначе в индекс попадёт
        // километр javascript вместо содержания страницы.
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])\s*/?>#i', "\n", $html) ?? $html;

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function normalize(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            // Сетевые папки часто отдают windows-1251 — переводим.
            $text = mb_convert_encoding($text, 'UTF-8', ['UTF-8', 'Windows-1251', 'KOI8-R', 'ISO-8859-5']);
        }

        $text = str_replace(["\r\n", "\r", "\x00"], ["\n", "\n", ''], $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function fromPdf(string $path): string
    {
        return (new PdfParser())->parseFile($path)->getText();
    }

    private function fromWord(string $path, string $extension): string
    {
        $reader = match ($extension) {
            'docx' => 'Word2007',
            'doc'  => 'MsDoc',
            'odt'  => 'ODText',
            'rtf'  => 'RTF',
        };

        $document = IOFactory::createReader($reader)->load($path);
        $parts = [];

        foreach ($document->getSections() as $section) {
            $parts[] = $this->walkWordElements($section->getElements());
        }

        return implode("\n", $parts);
    }

    private function walkWordElements(array $elements): string
    {
        $out = [];

        foreach ($elements as $element) {
            if (method_exists($element, 'getText')) {
                $out[] = (string) $element->getText();
            } elseif (method_exists($element, 'getElements')) {
                $out[] = $this->walkWordElements($element->getElements());
            } elseif (method_exists($element, 'getRows')) {
                foreach ($element->getRows() as $row) {
                    $cells = [];
                    foreach ($row->getCells() as $cell) {
                        $cells[] = trim($this->walkWordElements($cell->getElements()));
                    }
                    $out[] = implode(' | ', $cells);
                }
            }
        }

        return implode("\n", array_filter($out, fn ($line) => trim((string) $line) !== ''));
    }

    private function fromCsv(string $path): string
    {
        $handle = fopen($path, 'r');
        $lines = [];

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $lines[] = implode(' | ', array_map(fn ($v) => trim((string) $v), $row));

            if (count($lines) > 20000) {
                break; // защита от гигантских выгрузок
            }
        }

        fclose($handle);

        return implode("\n", $lines);
    }

    private function fromJson(string $path): string
    {
        $data = json_decode(file_get_contents($path), true);

        return $data === null
            ? file_get_contents($path)
            : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
