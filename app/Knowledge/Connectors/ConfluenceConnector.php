<?php

namespace App\Knowledge\Connectors;

use App\Knowledge\Contracts\Connector;
use App\Knowledge\DocumentDraft;
use App\Knowledge\TextExtractor;
use App\Models\KnowledgeSource;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Confluence (вики от Atlassian, та же учётка, что и у Jira).
 *
 * Поддерживаем оба варианта, потому что заранее неизвестно, какой у клиента:
 *  - Cloud (*.atlassian.net): почта + API-токен;
 *  - Server / Data Center: персональный токен (Bearer) или логин с паролем.
 */
class ConfluenceConnector implements Connector
{
    public function __construct(private readonly KnowledgeSource $source)
    {
    }

    public static function key(): string
    {
        return 'confluence';
    }

    public static function label(): string
    {
        return 'Confluence (база знаний Jira)';
    }

    public static function help(): string
    {
        return 'Страницы Confluence. Для Cloud нужен e-mail и API-токен, для Server — персональный токен или логин с паролем.';
    }

    public static function fields(): array
    {
        return [
            ['name' => 'base_url', 'label' => 'Адрес Confluence', 'type' => 'text', 'required' => true,
             'placeholder' => 'https://company.atlassian.net/wiki',
             'help' => 'Для Cloud адрес заканчивается на /wiki. Для Server — просто адрес сайта.'],

            ['name' => 'auth_type', 'label' => 'Способ авторизации', 'type' => 'select', 'required' => true,
             'default' => 'cloud_token',
             'options' => [
                 'cloud_token' => 'Cloud: e-mail + API-токен',
                 'pat'         => 'Server/DC: персональный токен (Bearer)',
                 'basic'       => 'Server/DC: логин + пароль',
             ]],

            ['name' => 'username', 'label' => 'E-mail или логин', 'type' => 'text',
             'help' => 'Не нужен, если выбран персональный токен.'],

            ['name' => 'token', 'label' => 'API-токен / пароль', 'type' => 'password', 'required' => true],

            ['name' => 'spaces', 'label' => 'Пространства (ключи)', 'type' => 'text',
             'placeholder' => 'BUH, IT, HR',
             'help' => 'Через запятую. Пусто — забираем все доступные пространства.'],

            ['name' => 'cql', 'label' => 'Дополнительный фильтр CQL', 'type' => 'text',
             'placeholder' => 'lastmodified > now("-365d")',
             'help' => 'Необязательно. Язык запросов Confluence, если нужно сузить выборку.'],

            ['name' => 'include_attachments', 'label' => 'Индексировать вложения страниц', 'type' => 'checkbox',
             'default' => false,
             'help' => 'Файлы, прикреплённые к страницам (pdf, docx). Заметно удлиняет синхронизацию.'],
        ];
    }

    public function test(): array
    {
        $response = $this->http()->get('/rest/api/space', ['limit' => 1]);

        if ($response->status() === 401 || $response->status() === 403) {
            return ['ok' => false, 'message' => 'Confluence не принял учётные данные (ошибка '.$response->status().').'];
        }

        if ($response->failed()) {
            return ['ok' => false, 'message' => 'Confluence ответил ошибкой '.$response->status().'. Проверьте адрес.'];
        }

        $total = $this->http()->get('/rest/api/content/search', ['cql' => $this->cql(), 'limit' => 1])->json('totalSize');

        return ['ok' => true, 'message' => 'Подключение есть. Страниц под фильтр попадает: '.($total ?? '?')];
    }

    public function documents(): iterable
    {
        $extractor = new TextExtractor();
        $start = 0;
        $limit = 50;

        do {
            $response = $this->http()->get('/rest/api/content/search', [
                'cql'    => $this->cql(),
                'limit'  => $limit,
                'start'  => $start,
                'expand' => 'body.storage,version,space,history.lastUpdated',
            ]);

            if ($response->failed()) {
                throw new \RuntimeException('Confluence ответил ошибкой '.$response->status());
            }

            $results = $response->json('results') ?? [];

            foreach ($results as $page) {
                $html = $page['body']['storage']['value'] ?? '';
                $text = $extractor->normalize($extractor->fromHtml($html));

                if ($text === '') {
                    continue;
                }

                $space = $page['space']['key'] ?? '';

                yield new DocumentDraft(
                    externalId: 'page:'.$page['id'],
                    title: $page['title'] ?? 'Без названия',
                    // Пространство и заголовок кладём в текст: по ним модель
                    // потом понимает, из какого раздела пришёл кусок.
                    content: "Пространство: {$space}\nСтраница: {$page['title']}\n\n{$text}",
                    uri: $this->baseUrl().($page['_links']['webui'] ?? ''),
                    mime: 'text/html',
                    meta: ['space' => $space, 'page_id' => $page['id'], 'version' => $page['version']['number'] ?? null],
                    updatedAt: isset($page['version']['when']) ? new \DateTimeImmutable($page['version']['when']) : null,
                );

                if ($this->source->plainConfig()['include_attachments'] ?? false) {
                    yield from $this->attachments($page, $extractor);
                }
            }

            $start += $limit;
        } while (count($results) === $limit);
    }

    private function attachments(array $page, TextExtractor $extractor): iterable
    {
        $response = $this->http()->get("/rest/api/content/{$page['id']}/child/attachment", ['limit' => 100]);

        foreach ($response->json('results') ?? [] as $attachment) {
            $extension = strtolower(pathinfo($attachment['title'] ?? '', PATHINFO_EXTENSION));

            if (! in_array($extension, config('rag.extensions'), true)) {
                continue;
            }

            $download = $this->http()->get($attachment['_links']['download'] ?? '');

            if ($download->failed()) {
                continue;
            }

            $local = tempnam(sys_get_temp_dir(), 'conf_').'.'.$extension;
            file_put_contents($local, $download->body());
            $text = $extractor->extract($local, $attachment['title']);
            @unlink($local);

            if (! $text) {
                continue;
            }

            yield new DocumentDraft(
                externalId: 'attachment:'.$attachment['id'],
                title: $attachment['title'],
                content: "Вложение страницы «{$page['title']}»\n\n{$text}",
                uri: $this->baseUrl().($attachment['_links']['download'] ?? ''),
                meta: ['page_id' => $page['id'], 'attachment' => true],
            );
        }
    }

    private function cql(): string
    {
        $config = $this->source->plainConfig();
        $parts = ['type in (page, blogpost)'];

        $spaces = array_filter(array_map('trim', explode(',', (string) ($config['spaces'] ?? ''))));

        if ($spaces) {
            $parts[] = 'space in ('.implode(',', array_map(fn ($s) => '"'.$s.'"', $spaces)).')';
        }

        if (! empty($config['cql'])) {
            $parts[] = '('.$config['cql'].')';
        }

        return implode(' AND ', $parts);
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->source->plainConfig()['base_url'] ?? ''), '/');
    }

    private function http(): PendingRequest
    {
        $config = $this->source->plainConfig();

        $request = Http::baseUrl($this->baseUrl())->acceptJson()->timeout(120);

        return match ($config['auth_type'] ?? 'cloud_token') {
            'pat'   => $request->withToken($config['token'] ?? ''),
            default => $request->withBasicAuth($config['username'] ?? '', $config['token'] ?? ''),
        };
    }
}
