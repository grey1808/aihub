<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Attachments\SpeechToText;
use App\Llm\LlmClient;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\Document;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(LlmClient $llm, SpeechToText $speech)
    {
        return view('admin.dashboard', [
            'llm' => $llm->ping(),
            'stt' => $speech->ping(),
            'stats' => [
                'sources'   => KnowledgeSource::count(),
                'enabled'   => KnowledgeSource::where('is_enabled', true)->count(),
                'documents' => Document::count(),
                'pending'   => Document::where('index_status', 'pending')->count(),
                'failed'    => Document::where('index_status', 'failed')->count(),
                'chunks'    => DB::table('document_chunks')->count(),
                'users'     => User::count(),
                'messages'  => ChatMessage::where('role', 'user')->count(),
                'files'     => ChatAttachment::count(),
            ],
            'problems' => KnowledgeSource::where('last_status', 'error')->get(),
        ]);
    }
}
