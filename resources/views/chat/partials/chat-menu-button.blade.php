{{-- Три точки, появляются при наведении на чат.
     Меню одно на всю панель — в него подставляется нужный чат. --}}
<button type="button"
        data-chat-menu="{{ $item->id }}"
        data-chat-title="{{ $item->title }}"
        data-chat-project="{{ $item->project_id }}"
        aria-label="Действия с чатом"
        class="absolute right-1 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded text-gray-400
               opacity-0 transition-opacity hover:bg-gray-200 hover:text-gray-700
               focus:opacity-100 group-hover:opacity-100">
    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
        <circle cx="10" cy="4" r="1.5"/>
        <circle cx="10" cy="10" r="1.5"/>
        <circle cx="10" cy="16" r="1.5"/>
    </svg>
</button>
