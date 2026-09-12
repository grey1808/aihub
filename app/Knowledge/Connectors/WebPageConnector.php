<?php

namespace App\Knowledge\Connectors;

use App\Knowledge\Contracts\Connector;
use App\Knowledge\DocumentDraft;
use App\Knowledge\TextExtractor;
use App\Models\KnowledgeSource;
use Illuminate\Support\Facades\Http;

/**
 * Отдельные веб-страницы во внутренней сети: портал, вики, регламенты на
 * интранет-сайте. Обхода по ссылкам нет специально — адреса задаёт админ,
 * иначе робот уйдёт гулять по всему интранету.
 */
class WebPageConnector implements Connector
{
    public function __construct(private readonly KnowledgeSource $source)
    {
    }

    public static function key(): string
    {
        return 'web';
    }

    public static function label(): string
    {
        return 'Веб-страницы по списку адресов';
    }

    public static function help(): string
    {
        return 'Список конкретных URL внутренней сети. Каждая страница станет документом.';
    }

    public static function fields(): array
    {
        return [
            ['name' => 'urls', 'label' => 'Адреса страниц', 'type' => 'textarea', 'rows' => 8, 'required' => true,
             'placeholder' => "http://intranet/reglament-otpusk\nhttp://intranet/kontakty",
             'help' => 'По одному адресу в строке.'],

            ['name' => 'username', 'label' => 'Логин (если нужна авторизация)', 'type' => 'text'],
            ['name' => 'password', 'label' => 'Пароль', 'type' => 'password'],
        ];
    }

    public function test(): array
    {
        $urls = $this->urls();

        if ($urls === []) {
            return ['ok' => false, 'message' => 'Не указано ни одного адреса.'];
        }

        $response = $this->fetch($urls[0]);

        return $response->successful()
            ? ['ok' => true, 'message' => 'Первая страница открылась, адресов в списке: '.count($urls)]
            : ['ok' => false, 'message' => 'Первая страница ответила ошибкой '.$response->status()];
    }

    public function documents(): iterable
    {
        $extractor = new TextExtractor();

        foreach ($this->urls() as $url) {
            $response = $this->fetch($url);

            if ($response->failed()) {
                continue;
            }

            $html = $response->body();
            preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m);
            $text = $extractor->normalize($extractor->fromHtml($html));

            if ($text === '') {
                continue;
            }

            yield new DocumentDraft(
                externalId: $url,
                title: trim($m[1] ?? $url),
                content: $text,
                uri: $url,
                mime: 'text/html',
            );
        }
    }

    private function urls(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode("\n", (string) ($this->source->plainConfig()['urls'] ?? ''))
        )));
    }

    private function fetch(string $url)
    {
        $config = $this->source->plainConfig();
        $request = Http::timeout(60)->withOptions(['verify' => false]);

        if (! empty($config['username'])) {
            $request = $request->withBasicAuth($config['username'], $config['password'] ?? '');
        }

        return $request->get($url);
    }
}
