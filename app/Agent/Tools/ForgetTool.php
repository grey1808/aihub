<?php

namespace App\Agent\Tools;

use App\Models\User;

/** Удаление заметок из личной памяти. */
class ForgetTool implements Tool
{
    private ?User $user = null;

    /**
     * Кому принадлежит память. Ставится агентом перед вызовом —
     * на сессию не завязываемся: ответ может готовиться и вне запроса.
     */
    public function forUser(?User $user): void
    {
        $this->user = $user;
    }

    public function name(): string
    {
        return 'forget';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => 'Убрать заметку из личной памяти о сотруднике. '
                    .'Используй, когда он просит забыть что-то или когда заметка устарела.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'about' => [
                            'type' => 'string',
                            'description' => 'Несколько слов из заметки, которую надо убрать. '
                                .'Пустая строка или слово «всё» — очистить память целиком.',
                        ],
                    ],
                    'required' => ['about'],
                ],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $user = $this->user;

        if (! $user || trim((string) $user->memory) === '') {
            return ['output' => 'Память пуста, забывать нечего.'];
        }

        $about = trim((string) ($arguments['about'] ?? ''));

        if ($about === '' || in_array(mb_strtolower($about), ['всё', 'все', 'всю память', 'память'], true)) {
            $user->update(['memory' => null, 'memory_updated_at' => now()]);

            return ['output' => 'Память очищена полностью.'];
        }

        $lines = preg_split('/\R/u', (string) $user->memory) ?: [];
        $needle = mb_strtolower($about);

        $kept = array_values(array_filter(
            $lines,
            fn (string $line) => ! str_contains(mb_strtolower($line), $needle)
        ));

        $removed = count($lines) - count($kept);

        if ($removed === 0) {
            return ['output' => 'Заметки про «'.$about.'» в памяти нет. Ничего не изменил.'];
        }

        $user->update([
            'memory'            => trim(implode("\n", $kept)) ?: null,
            'memory_updated_at' => now(),
        ]);

        return ['output' => "Убрал из памяти заметок: {$removed}."];
    }
}
