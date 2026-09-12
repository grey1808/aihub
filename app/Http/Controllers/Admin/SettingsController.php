<?php

namespace App\Http\Controllers\Admin;

use App\Agent\PromptBuilder;
use App\Http\Controllers\Controller;
use App\Llm\LlmClient;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function edit(LlmClient $llm)
    {
        return view('admin.settings', [
            'systemPrompt'  => Setting::get('system_prompt', PromptBuilder::DEFAULT_PROMPT),
            'companyName'   => Setting::get('company_name', ''),
            'historyLimit'  => Setting::get('history_limit', 12),
            'defaultPrompt' => PromptBuilder::DEFAULT_PROMPT,
            'llm'           => $llm->ping(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'system_prompt' => ['required', 'string', 'max:20000'],
            'company_name'  => ['nullable', 'string', 'max:200'],
            'history_limit' => ['required', 'integer', 'min:2', 'max:50'],
        ]);

        foreach ($data as $key => $value) {
            Setting::put($key, (string) $value);
        }

        return back()->with('status', 'Настройки сохранены.');
    }
}
