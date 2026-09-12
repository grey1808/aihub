<?php

namespace App\Knowledge\Connectors;

use App\Knowledge\Contracts\Connector;
use App\Knowledge\DocumentDraft;
use App\Knowledge\TextExtractor;
use App\Models\KnowledgeSource;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Папка с файлами, видимая приложению.
 *
 * Сюда же попадают сетевые папки, если их примонтировали на самом
 * моноблоке средствами системы (fstab/autofs) — приложение видит их
 * как обычный путь. Если монтировать нечем, есть отдельный тип "smb".
 */
class LocalFilesConnector implements Connector
{
    public function __construct(private readonly KnowledgeSource $source)
    {
    }

    public static function key(): string
    {
        return 'local_files';
    }

    public static function label(): string
    {
        return 'Папка с файлами';
    }

    public static function help(): string
    {
        return 'Каталог с документами на самом компьютере или уже примонтированная сетевая папка. '
             .'Внутри контейнера каталоги из docker-compose видны по пути /data/<имя>.';
    }

    public static function fields(): array
    {
        return [
            [
                'name'        => 'path',
                'label'       => 'Путь к папке',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => '/data/documents/buhgalteriya',
                'help'        => 'Путь внутри контейнера. Папки, проброшенные в docker-compose, лежат в /data.',
            ],
            [
                'name'        => 'extensions',
                'label'       => 'Расширения файлов',
                'type'        => 'text',
                'placeholder' => 'pdf,docx,xlsx,txt,md',
                'help'        => 'Через запятую. Пусто — берём все поддерживаемые типы.',
            ],
            [
                'name'        => 'exclude',
                'label'       => 'Исключить',
                'type'        => 'text',
                'placeholder' => '*/archive/*, ~$*',
                'help'        => 'Маски файлов и папок через запятую, которые не надо индексировать.',
            ],
            [
                'name'    => 'recursive',
                'label'   => 'Заходить во вложенные папки',
                'type'    => 'checkbox',
                'default' => true,
            ],
        ];
    }

    public function test(): array
    {
        $path = $this->path();

        if (! is_dir($path)) {
            return ['ok' => false, 'message' => "Папка не найдена: {$path}"];
        }

        if (! is_readable($path)) {
            return ['ok' => false, 'message' => "Нет прав на чтение папки: {$path}"];
        }

        $count = iterator_count($this->finder());

        return ['ok' => true, 'message' => "Папка доступна, подходящих файлов: {$count}"];
    }

    public function documents(): iterable
    {
        $extractor = new TextExtractor();
        $path = $this->path();

        /** @var SplFileInfo $file */
        foreach ($this->finder() as $file) {
            $content = $extractor->extract($file->getRealPath(), $file->getFilename());

            if (! $content) {
                continue; // нечитаемый или пустой файл — молча пропускаем
            }

            $relative = ltrim(str_replace($path, '', $file->getRealPath()), '/');

            yield new DocumentDraft(
                externalId: $relative,
                title: $file->getFilename(),
                content: $content,
                uri: $file->getRealPath(),
                mime: mime_content_type($file->getRealPath()) ?: null,
                meta: [
                    'folder' => dirname($relative) === '.' ? '' : dirname($relative),
                    'size'   => $file->getSize(),
                ],
                updatedAt: (new \DateTimeImmutable())->setTimestamp($file->getMTime()),
            );
        }
    }

    private function path(): string
    {
        return rtrim((string) ($this->source->plainConfig()['path'] ?? ''), '/');
    }

    private function finder(): Finder
    {
        $config = $this->source->plainConfig();

        $extensions = $this->listOf($config['extensions'] ?? '') ?: config('rag.extensions');

        $finder = Finder::create()
            ->files()
            ->in($this->path())
            ->size('< '.config('rag.max_file_size'))
            ->ignoreUnreadableDirs();

        if (! ($config['recursive'] ?? true)) {
            $finder->depth(0);
        }

        foreach ($extensions as $extension) {
            $finder->name('*.'.ltrim(strtolower($extension), '.'));
        }

        foreach ($this->listOf($config['exclude'] ?? '') as $pattern) {
            $finder->notPath(trim($pattern, '*/'))->notName($pattern);
        }

        return $finder;
    }

    private function listOf(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }
}
