@php($isUser = $message->role === 'user')

<div class="flex {{ $isUser ? 'justify-end' : 'justify-start' }}">
    <div class="max-w-[85%] rounded-2xl px-3.5 py-2.5 shadow-sm sm:max-w-3xl sm:px-4 sm:py-3
                {{ $isUser ? 'bg-indigo-600 text-white' : 'border border-gray-200 bg-white text-gray-900' }}">

        @if ($message->relationLoaded('attachments') ? $message->attachments->isNotEmpty() : $message->attachments()->exists())
            <div class="mb-2 space-y-2">
                @foreach ($message->attachments as $attachment)
                    @include('chat.partials.attachment', ['attachment' => $attachment, 'isUser' => $isUser])
                @endforeach
            </div>
        @endif

        @if ($isUser)
            @if (trim($message->content) !== '')
                <div class="whitespace-pre-wrap break-words">{{ $message->content }}</div>
            @endif
        @else
            <div class="prose prose-sm max-w-none break-words">
                {!! \App\Support\Markdown::toHtml($message->content) !!}
            </div>

            @if ($message->sources)
                <details class="mt-3 border-t border-gray-100 pt-2">
                    <summary class="cursor-pointer text-xs text-gray-500 hover:text-gray-700">
                        Источники ответа ({{ count($message->sources) }})
                    </summary>

                    <ul class="mt-2 space-y-2">
                        @foreach ($message->sources as $source)
                            <li class="text-xs text-gray-600">
                                <span class="font-medium text-gray-800">{{ $source['title'] ?? 'Документ' }}</span>
                                <span class="text-gray-400">— {{ $source['source'] ?? '' }}</span>

                                @if (! empty($source['uri']))
                                    <div class="break-all text-gray-400">{{ $source['uri'] }}</div>
                                @endif

                                @if (! empty($source['excerpt']))
                                    <div class="mt-1 italic text-gray-500">{{ $source['excerpt'] }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif

            @if ($message->duration_ms)
                <div class="mt-2 text-[11px] text-gray-400">
                    Ответ за {{ round($message->duration_ms / 1000, 1) }} с
                </div>
            @endif
        @endif
    </div>
</div>
