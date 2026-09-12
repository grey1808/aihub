<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncSourceJob;
use App\Knowledge\ConnectorRegistry;
use App\Models\KnowledgeSource;
use Illuminate\Http\Request;

class KnowledgeSourceController extends Controller
{
    public function __construct(private readonly ConnectorRegistry $registry)
    {
    }

    public function index()
    {
        return view('admin.sources.index', [
            'sources'  => KnowledgeSource::withCount('documents')->orderBy('id')->get(),
            'registry' => $this->registry,
        ]);
    }

    public function create(Request $request)
    {
        $type = $request->query('type');

        return view('admin.sources.create', [
            'registry' => $this->registry,
            'type'     => $this->registry->has((string) $type) ? $type : null,
        ]);
    }

    public function store(Request $request)
    {
        $type = (string) $request->input('type');

        abort_unless($this->registry->has($type), 404);

        $data = $this->validated($request, $type);

        $source = KnowledgeSource::create([
            'name'                  => $data['name'],
            'type'                  => $type,
            'description'           => $data['description'] ?? null,
            'is_enabled'            => (bool) ($data['is_enabled'] ?? true),
            'sync_interval_minutes' => (int) ($data['sync_interval_minutes'] ?? 60),
            'config'                => $data['config'] ?? [],
        ]);

        return redirect()
            ->route('admin.sources.edit', $source)
            ->with('status', 'Источник создан. Нажмите «Проверить подключение», затем «Синхронизировать».');
    }

    public function edit(KnowledgeSource $source)
    {
        return view('admin.sources.edit', [
            'source'   => $source,
            'registry' => $this->registry,
            'runs'     => $source->syncRuns()->limit(10)->get(),
        ]);
    }

    public function update(Request $request, KnowledgeSource $source)
    {
        $data = $this->validated($request, $source->type);

        $config = $data['config'] ?? [];

        // Пустое поле пароля означает «оставить прежний», а не «стереть».
        // Иначе при каждом сохранении формы пароли бы обнулялись.
        foreach ($this->registry->secretFields($source->type) as $field) {
            if (($config[$field] ?? '') === '') {
                unset($config[$field]);
                $config[$field] = $source->plainConfig()[$field] ?? '';
            }
        }

        $source->update([
            'name'                  => $data['name'],
            'description'           => $data['description'] ?? null,
            'is_enabled'            => (bool) ($data['is_enabled'] ?? false),
            'sync_interval_minutes' => (int) ($data['sync_interval_minutes'] ?? 60),
            'config'                => $config,
        ]);

        return back()->with('status', 'Сохранено.');
    }

    public function destroy(KnowledgeSource $source)
    {
        $source->delete();

        return redirect()->route('admin.sources.index')->with('status', 'Источник удалён вместе с его документами.');
    }

    /** Кнопка «Проверить подключение». */
    public function test(KnowledgeSource $source)
    {
        try {
            $result = $source->connector()->test();
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }

        return back()->with($result['ok'] ? 'status' : 'error', $result['message']);
    }

    /** Кнопка «Синхронизировать сейчас». */
    public function sync(KnowledgeSource $source)
    {
        SyncSourceJob::dispatch($source->id);

        return back()->with('status', 'Синхронизация запущена в фоне. Обновите страницу через минуту.');
    }

    private function validated(Request $request, string $type): array
    {
        return $request->validate(array_merge([
            'name'                  => ['required', 'string', 'max:120'],
            'description'           => ['nullable', 'string', 'max:2000'],
            'is_enabled'            => ['nullable', 'boolean'],
            'sync_interval_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ], $this->registry->validationRules($type)), [], [
            'name'        => 'название',
            'description' => 'описание',
        ]);
    }
}
