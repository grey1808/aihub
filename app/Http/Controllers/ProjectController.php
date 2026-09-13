<?php

namespace App\Http\Controllers;

use App\Knowledge\ConnectorRegistry;
use App\Models\KnowledgeSource;
use App\Models\Project;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ], [], ['name' => 'название']);

        $project = $request->user()->projects()->create([
            'name'         => $data['name'],
            'last_used_at' => now(),
        ]);

        return redirect()->route('projects.show', $project)
            ->with('status', 'Проект создан. Опишите тему и добавьте инструкцию — помощник будет им следовать во всех чатах проекта.');
    }

    public function show(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        return view('projects.show', [
            'project'  => $project,
            'threads'  => $project->threads()->get(),
            // Чаты, которые можно добавить сюда: свои и пока не в этом проекте.
            'available' => $request->user()->threads()
                ->with('project')
                ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', '!=', $project->id))
                ->limit(100)
                ->get(),
            'sources'  => KnowledgeSource::where('is_enabled', true)->orderBy('id')->get(),
            'registry' => app(ConnectorRegistry::class),
        ]);
    }

    public function update(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $data = $request->validate([
            'name'         => ['required', 'string', 'max:120'],
            'description'  => ['nullable', 'string', 'max:2000'],
            'instructions' => ['nullable', 'string', 'max:8000'],
            'notes'        => ['nullable', 'string', 'max:20000'],
            'source_ids'   => ['array'],
            'source_ids.*' => ['integer'],
        ], [], [
            'name'         => 'название',
            'instructions' => 'инструкция',
            'notes'        => 'заметки',
        ]);

        $notesChanged = trim((string) ($data['notes'] ?? '')) !== trim((string) $project->notes);

        $project->update([
            'name'         => $data['name'],
            'description'  => $data['description'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'notes'        => trim((string) ($data['notes'] ?? '')) ?: null,
            'source_ids'   => $data['source_ids'] ?? [],
        ] + ($notesChanged ? ['notes_updated_at' => now()] : []));

        return back()->with('status', 'Сохранено.');
    }

    /** Добавить в проект уже существующие чаты — списком, галочками. */
    public function attach(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $data = $request->validate([
            'threads'   => ['required', 'array'],
            'threads.*' => ['integer'],
        ], [], ['threads' => 'чаты']);

        $moved = $request->user()->threads()
            ->whereIn('id', $data['threads'])
            ->update(['project_id' => $project->id]);

        return back()->with('status', $moved === 1
            ? 'Чат добавлен в проект.'
            : "Чатов добавлено: {$moved}.");
    }

    public function destroy(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        // Чаты не трогаем: они просто выпадают из папки. Удалять переписку
        // вместе с папкой слишком грубо — люди складывают в проекты месяцами.
        $project->threads()->update(['project_id' => null]);
        $project->delete();

        return redirect()->route('chat.index')
            ->with('status', 'Проект удалён. Его чаты остались и лежат теперь без проекта.');
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        abort_unless($project->user_id === $request->user()->id, 403);
    }
}
