<?php

namespace App\Knowledge;

/** Один документ, каким его отдал коннектор, до записи в базу. */
class DocumentDraft
{
    public function __construct(
        public string $externalId,      // стабильный идентификатор внутри источника
        public string $title,
        public string $content,
        public ?string $uri = null,     // где человеку посмотреть оригинал
        public ?string $mime = null,
        public array $meta = [],
        public ?\DateTimeInterface $updatedAt = null,
    ) {
    }

    public function hash(): string
    {
        return hash('sha256', $this->title."\n".$this->content);
    }
}
