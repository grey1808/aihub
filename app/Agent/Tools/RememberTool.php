<?php

namespace App\Agent\Tools;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Запись в личную память помощника.
 *
 * Заметки видит только сам сотрудник, и помощник читает их перед каждым
 * ответом — они целиком попадают в системное сообщение. Поэтому отдельного
 * инструмента «прочитать память» нет: читать нечего, всё уже перед глазами.
 */
class RememberTool implements Tool
{
    private ?User $user = null;
    private ?Project $project = null;

    /**
     * Кому принадлежит память. Ставится агентом перед вызовом —
     * на сессию не завязываемся: ответ может готовиться и вне запроса.
     */
    public function forUser(?User $user): void
    {
        $this->user = $user;
    }

    /** Проект текущего разговора, если он есть. */
    public function forProject(?Project $project): void
    {
        $this->project = $project;
    }

    public function name(): string
    {
        return 'remember';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => 'Записать что-то в постоянную личную память об этом сотруднике. '
                    .'Используй, когда он просит запомнить («запомни, что…», «учитывай, что…», '
                    .'«всегда делай так-то») или когда сам узнал о нём что-то, что пригодится в будущих разговорах: '
                    .'над какими задачами работает, какие документы ему нужны обычно, как он любит получать ответы. '
                    .'Заметки читаются перед каждым ответом, в том числе в новых чатах. '
                    .'Не записывай содержание разового вопроса — только то, что пригодится потом.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'note' => [
                            'type' => 'string',
                            'description' => 'Одна короткая заметка, законченной фразой, на русском. '
                                .'Например: «Ведёт учёт по ООО «Ромашка», отчёты нужны в формате таблицы».',
                        ],
                        'scope' => [
                            'type' => 'string',
                            'enum' => ['personal', 'project'],
                            'description' => 'personal — про самого сотрудника, помнить всегда и везде. '
                                .'project — про тему текущего проекта: это увидят все чаты проекта, '
                                .'но только они. Если разговор идёт в проекте и заметка про тему, а не про человека — '
                                .'выбирай project.',
                        ],
                    ],
                    'required' => ['note'],
                ],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $user = $this->user;

        if (! $user) {
            return ['output' => 'Некому запоминать: пользователь не определён.'];
        }

        $note = trim((string) ($arguments['note'] ?? ''));

        if ($note === '') {
            return ['output' => 'Пустая заметка, записывать нечего.'];
        }

        $toProject = ($arguments['scope'] ?? 'personal') === 'project' && $this->project !== null;

        $target = $toProject ? $this->project : $user;
        $current = $toProject ? $this->project->notes : $user->memory;

        if (mb_strlen((string) $current) > 20000) {
            return ['output' => 'Заметки переполнены. Скажи сотруднику, что их стоит почистить '
                               .($toProject ? 'на странице проекта.' : 'в профиле.')];
        }

        $target->rememberNote($note);

        return ['output' => $toProject
            ? 'Записал в заметки проекта «'.$this->project->name.'»: '.Str::limit($note, 200)
            : 'Записал в личную память: '.Str::limit($note, 200)];
    }
}
