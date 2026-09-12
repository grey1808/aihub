<?php

namespace App\Http\Controllers;

use App\Agent\AgentRunner;
use App\Models\ChatAttachment;
use App\Models\ChatThread;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $thread = $request->user()->threads()->first();

        return $thread
            ? redirect()->route('chat.show', $thread)
            : view('chat.index', ['threads' => collect(), 'thread' => null]);
    }

    public function show(Request $request, ChatThread $thread)
    {
        $this->authorizeThread($request, $thread);

        return view('chat.index', [
            'threads' => $request->user()->threads()->get(),
            'thread'  => $thread->load('messages.attachments'),
        ]);
    }

    public function store(Request $request)
    {
        $thread = $request->user()->threads()->create([
            'title'           => 'Новый чат',
            'last_message_at' => now(),
        ]);

        return redirect()->route('chat.show', $thread);
    }

    public function send(Request $request, ChatThread $thread, AgentRunner $agent)
    {
        $this->authorizeThread($request, $thread);

        $data = $request->validate([
            // Сообщение может быть пустым, если приложили голосовое или файл —
            // заставлять человека что-то дописывать к голосовому глупо.
            'message'         => ['nullable', 'string', 'max:8000'],
            'attachments'     => ['array', 'max:'.(int) config('attachments.max_per_message')],
            'attachments.*'   => ['integer'],
        ], [
            'attachments.max' => 'К одному сообщению можно приложить не больше '
                                 .config('attachments.max_per_message').' файлов.',
        ], ['message' => 'сообщение']);

        $attachments = $this->claimAttachments($request->user()->id, $data['attachments'] ?? []);
        $text = trim((string) ($data['message'] ?? ''));

        if ($text === '' && $attachments->isEmpty()) {
            return $this->fail($request, 'Напишите вопрос или приложите файл.');
        }

        $message = $thread->messages()->create([
            'role'    => 'user',
            'content' => $text,
        ]);

        $attachments->each(fn (ChatAttachment $a) => $a->update([
            'chat_message_id' => $message->id,
            'chat_thread_id'  => $thread->id,
        ]));

        // Название чата делаем из первого вопроса — так список чатов
        // слева остаётся читаемым без участия пользователя.
        if ($thread->messages()->count() === 1) {
            $thread->update(['title' => Str::limit($this->titleFor($text, $attachments), 60)]);
        }

        $thread->update(['last_message_at' => now()]);

        $answer = $agent->answer($thread, $text, $attachments);

        if ($request->wantsJson()) {
            return response()->json([
                'content'      => $answer->content,
                'html'         => view('chat.partials.message', ['message' => $answer])->render(),
                'question_html'=> view('chat.partials.message', ['message' => $message->load('attachments')])->render(),
                'thread_title' => $thread->fresh()->title,
            ]);
        }

        return redirect()->route('chat.show', $thread);
    }

    public function destroy(Request $request, ChatThread $thread)
    {
        $this->authorizeThread($request, $thread);

        $thread->delete();

        return redirect()->route('chat.index');
    }

    public function rename(Request $request, ChatThread $thread)
    {
        $this->authorizeThread($request, $thread);

        $thread->update($request->validate([
            'title' => ['required', 'string', 'max:120'],
        ]));

        return back();
    }

    /**
     * Забираем загруженные ранее файлы и убеждаемся, что они наши
     * и ещё никуда не прикреплены.
     *
     * @return \Illuminate\Support\Collection<int, ChatAttachment>
     */
    private function claimAttachments(int $userId, array $ids)
    {
        if ($ids === []) {
            return collect();
        }

        return ChatAttachment::query()
            ->whereIn('id', $ids)
            ->where('user_id', $userId)
            ->whereNull('chat_message_id')
            ->get();
    }

    /** Заголовок чата: текст вопроса, а если его нет — имена файлов. */
    private function titleFor(string $text, $attachments): string
    {
        if ($text !== '') {
            return $text;
        }

        $first = $attachments->first();

        return match ($first?->kind) {
            ChatAttachment::KIND_AUDIO => 'Голосовое сообщение',
            ChatAttachment::KIND_IMAGE => 'Изображение: '.$first->original_name,
            null                       => 'Новый чат',
            default                    => 'Файл: '.$first->original_name,
        };
    }

    private function fail(Request $request, string $message)
    {
        return $request->wantsJson()
            ? response()->json(['message' => $message], 422)
            : back()->with('error', $message);
    }

    private function authorizeThread(Request $request, ChatThread $thread): void
    {
        abort_unless($thread->user_id === $request->user()->id, 403);
    }
}
