<?php

namespace App\Http\Controllers;

use App\Attachments\AttachmentProcessor;
use App\Models\ChatAttachment;
use App\Models\ChatThread;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentProcessor $processor)
    {
    }

    /** Загрузка файла до отправки сообщения. */
    public function store(Request $request)
    {
        $request->validate([
            'file'      => ['required', 'file', 'max:'.(int) (config('attachments.max_size') / 1024)],
            'thread_id' => ['nullable', 'integer'],
        ], [
            'file.max' => 'Файл слишком большой. Максимум — '
                          .round(config('attachments.max_size') / 1048576).' МБ.',
        ]);

        $thread = null;

        if ($request->filled('thread_id')) {
            $thread = ChatThread::find($request->integer('thread_id'));

            if ($thread && $thread->user_id !== $request->user()->id) {
                abort(403);
            }
        }

        try {
            $attachment = $this->processor->store($request->file('file'), $request->user()->id, $thread?->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($attachment), 201);
    }

    public function destroy(Request $request, ChatAttachment $attachment)
    {
        abort_unless($attachment->user_id === $request->user()->id, 403);

        // Удалять можно только то, что ещё не отправлено:
        // иначе переписка начнёт расходиться с тем, что видела модель.
        abort_if($attachment->chat_message_id !== null, 403, 'Отправленное вложение удалить нельзя.');

        $attachment->delete();

        return response()->noContent();
    }

    /** Отдача файла. Прямой ссылки на диск нет — только через эту проверку. */
    public function show(Request $request, ChatAttachment $attachment)
    {
        $owner = $attachment->user_id === $request->user()->id;

        // Своё вложение видно всегда; чужое — никому, даже администратору:
        // переписка сотрудника с помощником это его личная переписка.
        abort_unless($owner, 403);
        abort_unless($attachment->exists(), 404);

        return Response::file($attachment->disk()->path($attachment->path), [
            'Content-Type'        => $attachment->mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($attachment->original_name).'"',
        ]);
    }

    private function present(ChatAttachment $attachment): array
    {
        return [
            'id'        => $attachment->id,
            'kind'      => $attachment->kind,
            'name'      => $attachment->original_name,
            'size'      => $attachment->humanSize(),
            'icon'      => $attachment->icon(),
            'status'    => $attachment->status,
            'error'     => $attachment->error,
            'transcript'=> $attachment->kind === ChatAttachment::KIND_AUDIO ? $attachment->extracted_text : null,
            'url'       => route('attachments.show', $attachment),
        ];
    }
}
