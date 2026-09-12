<?php

namespace App\Rag;

/**
 * Режет документ на куски.
 *
 * Модели нельзя скормить документ на 200 страниц целиком, а искать по
 * документу целиком бессмысленно — вектор размажется по всем темам сразу.
 * Поэтому режем по абзацам с нахлёстом: нахлёст спасает мысль,
 * разорванную на границе двух кусков.
 */
class Chunker
{
    public function __construct(
        private readonly int $size = 0,
        private readonly int $overlap = 0,
    ) {
    }

    /** @return array<int, string> */
    public function split(string $text): array
    {
        $size = $this->size ?: (int) config('rag.chunk_size');
        $overlap = $this->overlap ?: (int) config('rag.chunk_overlap');

        $text = trim($text);

        if ($text === '') {
            return [];
        }

        if (mb_strlen($text) <= $size) {
            return [$text];
        }

        $paragraphs = preg_split('/\n{2,}/u', $text) ?: [$text];
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            // Абзац сам по себе длиннее куска — режем его по предложениям.
            if (mb_strlen($paragraph) > $size) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }

                foreach ($this->splitLongText($paragraph, $size) as $piece) {
                    $chunks[] = $piece;
                }

                continue;
            }

            if (mb_strlen($current) + mb_strlen($paragraph) + 2 > $size) {
                $chunks[] = $current;
                $current = $this->tail($current, $overlap);
            }

            $current = $current === '' ? $paragraph : $current."\n\n".$paragraph;
        }

        if (trim($current) !== '') {
            $chunks[] = $current;
        }

        return array_values(array_filter(array_map('trim', $chunks), fn ($c) => $c !== ''));
    }

    private function splitLongText(string $text, int $size): array
    {
        $sentences = preg_split('/(?<=[.!?;])\s+/u', $text) ?: [$text];
        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {
            // Даже одно предложение может быть длиннее куска (таблицы, простыни
            // без знаков препинания) — тогда режем жёстко по символам.
            while (mb_strlen($sentence) > $size) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }

                $chunks[] = mb_substr($sentence, 0, $size);
                $sentence = mb_substr($sentence, $size);
            }

            if (mb_strlen($current) + mb_strlen($sentence) + 1 > $size) {
                $chunks[] = $current;
                $current = '';
            }

            $current = $current === '' ? $sentence : $current.' '.$sentence;
        }

        if (trim($current) !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /** Хвост предыдущего куска, который уходит в начало следующего. */
    private function tail(string $text, int $overlap): string
    {
        if ($overlap <= 0 || mb_strlen($text) <= $overlap) {
            return '';
        }

        return trim(mb_substr($text, -$overlap));
    }
}
