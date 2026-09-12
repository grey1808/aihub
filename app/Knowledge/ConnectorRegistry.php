<?php

namespace App\Knowledge;

use App\Knowledge\Connectors\ConfluenceConnector;
use App\Knowledge\Connectors\LocalFilesConnector;
use App\Knowledge\Connectors\OneCODataConnector;
use App\Knowledge\Connectors\OneCSqlConnector;
use App\Knowledge\Connectors\SmbShareConnector;
use App\Knowledge\Connectors\WebPageConnector;
use App\Knowledge\Contracts\Connector;
use App\Models\KnowledgeSource;

class ConnectorRegistry
{
    /** @var array<string, class-string<Connector>> */
    private array $connectors = [];

    public function __construct()
    {
        foreach ([
            LocalFilesConnector::class,
            SmbShareConnector::class,
            ConfluenceConnector::class,
            OneCSqlConnector::class,
            OneCODataConnector::class,
            WebPageConnector::class,
        ] as $class) {
            $this->connectors[$class::key()] = $class;
        }
    }

    /** @return array<string, class-string<Connector>> */
    public function all(): array
    {
        return $this->connectors;
    }

    /** Список для выпадашки "тип источника" в админке. */
    public function options(): array
    {
        return collect($this->connectors)
            ->map(fn (string $class) => [
                'label' => $class::label(),
                'help'  => $class::help(),
            ])
            ->all();
    }

    public function has(string $type): bool
    {
        return isset($this->connectors[$type]);
    }

    /** @return class-string<Connector> */
    public function class(string $type): string
    {
        if (! $this->has($type)) {
            throw new \InvalidArgumentException("Неизвестный тип источника: {$type}");
        }

        return $this->connectors[$type];
    }

    public function label(string $type): string
    {
        return $this->has($type) ? $this->class($type)::label() : $type;
    }

    public function fields(string $type): array
    {
        return $this->class($type)::fields();
    }

    /** Поля, которые надо хранить в зашифрованном виде. */
    public function secretFields(?string $type): array
    {
        if (! $type || ! $this->has($type)) {
            return [];
        }

        return collect($this->fields($type))
            ->filter(fn (array $f) => ($f['secret'] ?? false) || ($f['type'] ?? '') === 'password')
            ->pluck('name')
            ->all();
    }

    public function make(KnowledgeSource $source): Connector
    {
        $class = $this->class($source->type);

        return new $class($source);
    }

    /** Правила валидации формы источника, собранные из описания полей. */
    public function validationRules(string $type): array
    {
        $rules = [];

        foreach ($this->fields($type) as $field) {
            $rule = [($field['required'] ?? false) ? 'required' : 'nullable'];

            $rule[] = match ($field['type'] ?? 'text') {
                'number'   => 'integer',
                'checkbox' => 'boolean',
                default    => 'string',
            };

            if (! empty($field['options'])) {
                $rule[] = 'in:'.implode(',', array_keys($field['options']));
            }

            $rules['config.'.$field['name']] = $rule;
        }

        return $rules;
    }
}
