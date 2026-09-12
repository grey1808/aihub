<?php

namespace App\Knowledge\Connectors;

use App\Knowledge\Contracts\Connector;
use App\Knowledge\DocumentDraft;
use App\Knowledge\TextExtractor;
use App\Models\KnowledgeSource;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Общая сетевая папка Windows (SMB/CIFS).
 *
 * Ходим через smbclient — он ставится в образ и не требует прав root
 * на монтирование, в отличие от mount -t cifs.
 */
class SmbShareConnector implements Connector
{
    public function __construct(private readonly KnowledgeSource $source)
    {
    }

    public static function key(): string
    {
        return 'smb';
    }

    public static function label(): string
    {
        return 'Сетевая папка Windows (SMB)';
    }

    public static function help(): string
    {
        return 'Общая папка вида \\\\server\\share. Нужна учётная запись с правом чтения.';
    }

    public static function fields(): array
    {
        return [
            ['name' => 'host', 'label' => 'Сервер', 'type' => 'text', 'required' => true,
             'placeholder' => 'fileserver или 192.168.1.10'],

            ['name' => 'share', 'label' => 'Имя общего ресурса', 'type' => 'text', 'required' => true,
             'placeholder' => 'Documents', 'help' => 'То, что идёт после имени сервера в \\\\server\\share.'],

            ['name' => 'path', 'label' => 'Подпапка внутри ресурса', 'type' => 'text',
             'placeholder' => 'Бухгалтерия/Регламенты', 'help' => 'Пусто — берём весь ресурс.'],

            ['name' => 'username', 'label' => 'Пользователь', 'type' => 'text', 'required' => true],
            ['name' => 'password', 'label' => 'Пароль', 'type' => 'password', 'required' => true],
            ['name' => 'domain', 'label' => 'Домен', 'type' => 'text', 'placeholder' => 'WORKGROUP'],

            ['name' => 'extensions', 'label' => 'Расширения файлов', 'type' => 'text',
             'placeholder' => 'pdf,docx,txt', 'help' => 'Через запятую. Пусто — все поддерживаемые.'],
        ];
    }

    public function test(): array
    {
        $result = $this->smb('ls');

        if (! $result['ok']) {
            return ['ok' => false, 'message' => 'Не подключились: '.Str::limit($result['output'], 300)];
        }

        return ['ok' => true, 'message' => 'Подключение есть, папка читается.'];
    }

    public function documents(): iterable
    {
        $extractor = new TextExtractor();
        $extensions = array_filter(array_map('trim', explode(',', (string) ($this->source->plainConfig()['extensions'] ?? ''))))
            ?: config('rag.extensions');

        foreach ($this->listFiles() as $file) {
            $extension = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));

            if (! in_array($extension, array_map('strtolower', $extensions), true)) {
                continue;
            }

            $local = $this->download($file['path']);

            if (! $local) {
                continue;
            }

            $content = $extractor->extract($local, basename($file['path']));
            @unlink($local);

            if (! $content) {
                continue;
            }

            yield new DocumentDraft(
                externalId: $file['path'],
                title: basename($file['path']),
                content: $content,
                uri: '\\\\'.$this->cfg('host').'\\'.$this->cfg('share').'\\'.str_replace('/', '\\', $file['path']),
                meta: ['folder' => dirname($file['path']), 'size' => $file['size']],
                updatedAt: $file['modified'],
            );
        }
    }

    /** Рекурсивный обход ресурса. smbclient сам умеет `recurse` + `ls`. */
    private function listFiles(): array
    {
        $result = $this->smb('recurse ON; prompt OFF; ls');

        if (! $result['ok']) {
            throw new \RuntimeException('Не удалось прочитать сетевую папку: '.Str::limit($result['output'], 300));
        }

        $files = [];
        $folder = '';

        foreach (explode("\n", $result['output']) as $line) {
            // Заголовок каталога: "\Бухгалтерия\Регламенты"
            if (preg_match('/^\\\\(.*)$/', trim($line), $m) && ! str_contains($line, ' N ')) {
                $folder = trim(str_replace('\\', '/', $m[1]), '/');

                continue;
            }

            // Строка файла: "  отчёт.pdf   A   123456  Mon Jan  1 10:00:00 2026"
            if (preg_match('/^\s{2}(.+?)\s+([ADHSRN]+)\s+(\d+)\s+(\w{3}\s+\w{3}\s+[\d\s]+\d{2}:\d{2}:\d{2}\s+\d{4})$/u', rtrim($line), $m)) {
                $name = trim($m[1]);

                if (in_array($name, ['.', '..'], true) || str_contains($m[2], 'D')) {
                    continue;
                }

                $files[] = [
                    'path'     => $folder === '' ? $name : $folder.'/'.$name,
                    'size'     => (int) $m[3],
                    'modified' => \DateTimeImmutable::createFromFormat('D M j H:i:s Y', preg_replace('/\s+/', ' ', $m[4])) ?: null,
                ];
            }
        }

        return $files;
    }

    private function download(string $path): ?string
    {
        $local = tempnam(sys_get_temp_dir(), 'smb_').'.'.pathinfo($path, PATHINFO_EXTENSION);
        $remote = str_replace('/', '\\', $path);

        $result = $this->smb(sprintf('get "%s" "%s"', $remote, $local));

        return $result['ok'] && filesize($local) ? $local : null;
    }

    private function smb(string $command): array
    {
        $config = $this->source->plainConfig();
        $subPath = trim((string) ($config['path'] ?? ''), '/\\');

        $args = [
            'smbclient',
            '//'.$this->cfg('host').'/'.$this->cfg('share'),
            '-U', ($config['domain'] ?? '') ? $config['domain'].'\\'.$this->cfg('username') : $this->cfg('username'),
            '-c', ($subPath ? 'cd "'.str_replace('/', '\\', $subPath).'"; ' : '').$command,
        ];

        $process = new Process($args, null, ['PASSWD' => (string) $this->cfg('password')], null, 600);
        $process->run();

        return [
            'ok'     => $process->isSuccessful(),
            'output' => $process->getOutput()."\n".$process->getErrorOutput(),
        ];
    }

    private function cfg(string $key): string
    {
        return (string) ($this->source->plainConfig()[$key] ?? '');
    }
}
