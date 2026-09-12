<?php

namespace App\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Модель отвечает markdown-ом: списки, таблицы, выделения.
 * Показываем это человеку как нормальный текст, но с экранированием —
 * ответ модели может содержать что угодно, включая html из документов.
 */
class Markdown
{
    public static function toHtml(string $text): string
    {
        $environment = new Environment([
            'html_input'         => 'escape',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());

        return (string) (new MarkdownConverter($environment))->convert($text);
    }
}
