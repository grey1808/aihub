{{-- Вложение внутри отправленного сообщения --}}
@php($isUser = $isUser ?? true)

@if ($attachment->kind === 'image')
    <a href="{{ route('attachments.show', $attachment) }}" target="_blank" class="block">
        <img src="{{ route('attachments.show', $attachment) }}"
             alt="{{ $attachment->original_name }}"
             loading="lazy"
             class="max-h-64 w-auto max-w-full rounded-lg border {{ $isUser ? 'border-indigo-400' : 'border-gray-200' }}">
    </a>

@elseif ($attachment->kind === 'audio')
    <div class="rounded-lg {{ $isUser ? 'bg-indigo-500/40' : 'bg-gray-50' }} p-2">
        <audio controls preload="none" class="w-full max-w-xs"
               src="{{ route('attachments.show', $attachment) }}"></audio>

        @if ($attachment->extracted_text)
            <div class="mt-1.5 text-sm italic {{ $isUser ? 'text-indigo-100' : 'text-gray-600' }}">
                «{{ $attachment->extracted_text }}»
            </div>
        @elseif ($attachment->status === 'failed')
            <div class="mt-1.5 text-xs {{ $isUser ? 'text-indigo-100' : 'text-red-600' }}">
                Расшифровать не удалось: {{ $attachment->error }}
            </div>
        @endif
    </div>

@else
    <a href="{{ route('attachments.show', $attachment) }}" target="_blank"
       class="flex items-center gap-2 rounded-lg {{ $isUser ? 'bg-indigo-500/40 hover:bg-indigo-500/60' : 'bg-gray-50 hover:bg-gray-100' }} px-3 py-2">
        <span class="text-lg">{{ $attachment->icon() }}</span>

        <span class="min-w-0 flex-1">
            <span class="block truncate text-sm">{{ $attachment->original_name }}</span>
            <span class="block text-xs {{ $isUser ? 'text-indigo-100' : 'text-gray-500' }}">
                {{ $attachment->humanSize() }}@if ($attachment->status === 'failed') · прочитать не удалось @endif
            </span>
        </span>
    </a>
@endif
