/**
 * Чат: вложения, запись голоса, отправка и мобильное меню.
 *
 * Всё на обычном JS без фреймворка — страница одна, состояние простое,
 * а лишняя библиотека на моноблоке без интернета только мешает.
 */

const form = document.getElementById('send-form');
const csrf = document.querySelector('meta[name=csrf-token]')?.content ?? '';

/* ------------------------------------------------------------------ */
/*  Боковая панель со списком чатов (на телефоне — выдвижная)          */
/* ------------------------------------------------------------------ */

const drawer = document.querySelector('[data-drawer]');
const overlay = document.querySelector('[data-drawer-overlay]');

const setDrawer = (open) => {
    if (!drawer) return;
    drawer.classList.toggle('-translate-x-full', !open);
    overlay?.classList.toggle('hidden', !open);
};

document.querySelector('[data-drawer-open]')?.addEventListener('click', () => setDrawer(true));
document.querySelector('[data-drawer-close]')?.addEventListener('click', () => setDrawer(false));
overlay?.addEventListener('click', () => setDrawer(false));

/* ------------------------------------------------------------------ */
/*  Меню пользователя                                                  */
/* ------------------------------------------------------------------ */

const menu = document.querySelector('[data-menu]');
const menuPanel = document.querySelector('[data-menu-panel]');

document.querySelector('[data-menu-toggle]')?.addEventListener('click', (event) => {
    event.stopPropagation();
    menuPanel?.classList.toggle('hidden');
});

document.addEventListener('click', (event) => {
    if (menu && !menu.contains(event.target)) menuPanel?.classList.add('hidden');
});

/* Меню действий с чатом */

const threadMenu = document.querySelector('[data-thread-menu]');
const threadPanel = document.querySelector('[data-thread-menu-panel]');

document.querySelector('[data-thread-menu-toggle]')?.addEventListener('click', (event) => {
    event.stopPropagation();
    threadPanel?.classList.toggle('hidden');
    menuPanel?.classList.add('hidden');
});

document.addEventListener('click', (event) => {
    if (threadMenu && !threadMenu.contains(event.target)) threadPanel?.classList.add('hidden');
});


/* ------------------------------------------------------------------ */
/*  Ширина боковой панели                                              */
/* ------------------------------------------------------------------ */

const resizer = document.querySelector('[data-resizer]');
const wide = () => window.matchMedia('(min-width: 768px)').matches;

const DEFAULT_WIDTH = 288; // соответствует классу w-72
const MIN_WIDTH = 200;
const MAX_WIDTH = 640;

const readWidth = () => {
    try {
        const saved = Number(localStorage.getItem('aihub:sidebar-width'));
        return saved >= MIN_WIDTH && saved <= MAX_WIDTH ? saved : null;
    } catch {
        return null; // приватный режим — просто работаем с шириной по умолчанию
    }
};

const applyWidth = (width) => {
    if (!drawer) return;

    // На узком экране панель выезжает поверх содержимого и всегда одной
    // ширины — заданная мышью ширина там только мешает.
    drawer.style.width = wide() && width ? width + 'px' : '';
};

applyWidth(readWidth());
window.addEventListener('resize', () => applyWidth(readWidth()));

if (resizer && drawer) {
    let dragging = false;

    const move = (event) => {
        if (!dragging) return;

        const width = Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, event.clientX - drawer.getBoundingClientRect().left));
        drawer.style.width = width + 'px';
    };

    const stop = () => {
        if (!dragging) return;

        dragging = false;
        document.body.style.userSelect = '';
        document.body.style.cursor = '';

        try {
            localStorage.setItem('aihub:sidebar-width', String(parseInt(drawer.style.width, 10)));
        } catch { /* не сохранилось — не беда */ }
    };

    resizer.addEventListener('mousedown', (event) => {
        event.preventDefault();
        dragging = true;
        // Пока тянем — гасим выделение текста, иначе страница «подсвечивается».
        document.body.style.userSelect = 'none';
        document.body.style.cursor = 'col-resize';
    });

    document.addEventListener('mousemove', move);
    document.addEventListener('mouseup', stop);

    resizer.addEventListener('dblclick', () => {
        drawer.style.width = '';
        try {
            localStorage.removeItem('aihub:sidebar-width');
        } catch { /* ничего */ }
    });
}

/* ------------------------------------------------------------------ */
/*  Перетаскивание чата в папку проекта                                */
/* ------------------------------------------------------------------ */

let draggedThread = null;

document.querySelectorAll('[data-thread-id]').forEach((link) => {
    link.addEventListener('dragstart', (event) => {
        draggedThread = link.dataset.threadId;
        event.dataTransfer.effectAllowed = 'move';
        // Без этого Firefox не начинает перетаскивание вовсе.
        event.dataTransfer.setData('text/plain', draggedThread);
        link.classList.add('opacity-40');
    });

    link.addEventListener('dragend', () => {
        link.classList.remove('opacity-40');
        draggedThread = null;
    });
});

document.querySelectorAll('[data-drop-project]').forEach((zone) => {
    const highlight = (on) => {
        // Через || нельзя: toggle возвращает булево, и второй вызов
        // пропускался бы, когда первый вернул true.
        zone.classList.toggle('ring-2', on);
        zone.classList.toggle('ring-inset', on);
        zone.classList.toggle('ring-indigo-400', on);
    };

    zone.addEventListener('dragover', (event) => {
        if (!draggedThread) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        highlight(true);
    });

    zone.addEventListener('dragleave', () => highlight(false));

    zone.addEventListener('drop', async (event) => {
        event.preventDefault();
        highlight(false);

        const threadId = draggedThread || event.dataTransfer.getData('text/plain');
        if (!threadId) return;

        try {
            // Именно PATCH, а не POST с _method: подмена метода читается
            // из формы, а в теле JSON Laravel её не видит.
            const response = await fetch('/chat/' + threadId + '/move', {
                method: 'PATCH',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ project_id: zone.dataset.dropProject || null }),
            });

            if (!response.ok) throw new Error('код ' + response.status);

            // Перечитываем страницу: так список папок и счётчики
            // гарантированно совпадают с тем, что в базе.
            window.location.reload();
        } catch (error) {
            alert('Не удалось перенести чат: ' + error.message);
        }
    });
});


/* ------------------------------------------------------------------ */
/*  Меню действий у чата в боковой панели                              */
/* ------------------------------------------------------------------ */

const chatActions = document.querySelector('[data-chat-actions]');

if (chatActions) {
    const renameForm = chatActions.querySelector('[data-form="rename"]');
    const moveForm = chatActions.querySelector('[data-form="move"]');
    const deleteForm = chatActions.querySelector('[data-form="delete"]');
    const titleInput = chatActions.querySelector('[data-title]');
    const projectSelect = chatActions.querySelector('[data-project]');

    const closeActions = () => chatActions.classList.add('hidden');

    document.querySelectorAll('[data-chat-menu]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            const id = button.dataset.chatMenu;

            renameForm.action = '/chat/' + id;
            moveForm.action = '/chat/' + id + '/move';
            deleteForm.action = '/chat/' + id;

            titleInput.value = button.dataset.chatTitle || '';
            projectSelect.value = button.dataset.chatProject || '';

            deleteForm.onsubmit = () =>
                confirm('Удалить чат «' + (button.dataset.chatTitle || '') + '»? Его можно будет вернуть из корзины.');

            // Показываем до замера: у скрытого элемента нет размеров.
            chatActions.classList.remove('hidden');

            const rect = button.getBoundingClientRect();
            const box = chatActions.getBoundingClientRect();

            // Прижимаем к кнопке, но не даём вылезти за край экрана.
            const left = Math.min(rect.right + 6, window.innerWidth - box.width - 8);
            const top = Math.min(rect.top, window.innerHeight - box.height - 8);

            chatActions.style.left = Math.max(8, left) + 'px';
            chatActions.style.top = Math.max(8, top) + 'px';

            titleInput.focus();
            titleInput.select();
        });
    });

    document.addEventListener('click', (event) => {
        if (!chatActions.contains(event.target)) closeActions();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeActions();
    });

    // Панель со списком чатов прокручивается — меню уехало бы от кнопки.
    document.querySelector('[data-drawer] nav')?.addEventListener('scroll', closeActions);
}

/* Дальше — только если на странице есть открытый чат. */
if (form) {
    const input = document.getElementById('message');
    const sendButton = document.getElementById('send-button');
    const thinking = document.getElementById('thinking');
    const list = document.getElementById('messages');
    const errorBox = document.getElementById('composer-error');
    const chips = document.getElementById('attachment-list');
    const fileInput = document.getElementById('file-input');

    const maxFiles = Number(form.dataset.maxFiles || 5);
    const maxSize = Number(form.dataset.maxSize || 33554432);

    /** Загруженные, но ещё не отправленные вложения. */
    let attachments = [];
    let sending = false;

    const scrollDown = () => { list.scrollTop = list.scrollHeight; };

    const showError = (text) => {
        errorBox.textContent = text;
        errorBox.classList.remove('hidden');
        setTimeout(() => errorBox.classList.add('hidden'), 8000);
    };

    /* -------------------------------------------------------------- */
    /*  Поле ввода                                                     */
    /* -------------------------------------------------------------- */

    // Поле растёт под текст, но не больше max-h-40 из вёрстки.
    const autoGrow = () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 160) + 'px';
    };

    input.addEventListener('input', autoGrow);

    // На телефоне Enter должен переносить строку: там это единственный
    // способ написать многострочный текст, а отправка — кнопкой.
    const isTouch = window.matchMedia('(pointer: coarse)').matches;

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !isTouch) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    /* -------------------------------------------------------------- */
    /*  Вложения                                                       */
    /* -------------------------------------------------------------- */

    const renderChips = () => {
        chips.innerHTML = '';
        chips.classList.toggle('hidden', attachments.length === 0);
        chips.classList.toggle('flex', attachments.length > 0);

        attachments.forEach((item) => {
            const chip = document.createElement('div');
            chip.className = 'flex max-w-full items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 py-1.5 pl-2 pr-1 text-sm';

            const label = document.createElement('span');
            label.className = 'flex min-w-0 items-center gap-1.5';

            if (item.status === 'uploading') {
                label.innerHTML = '<span class="inline-block h-3 w-3 animate-spin rounded-full border-2 border-gray-300 border-t-indigo-600"></span>';
            } else {
                label.textContent = item.icon || '📄';
            }

            const name = document.createElement('span');
            name.className = 'truncate max-w-[10rem]';
            name.textContent = item.name;
            label.appendChild(name);

            const meta = document.createElement('span');
            meta.className = 'text-xs text-gray-400 shrink-0';

            if (item.status === 'uploading') meta.textContent = 'загружается…';
            else if (item.status === 'failed') { meta.textContent = 'ошибка'; meta.className = 'text-xs text-red-600 shrink-0'; }
            else meta.textContent = item.size || '';

            label.appendChild(meta);
            chip.appendChild(label);

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'ml-1 flex h-7 w-7 shrink-0 items-center justify-center rounded text-gray-400 hover:bg-gray-200 hover:text-gray-700';
            remove.setAttribute('aria-label', 'Убрать файл');
            remove.textContent = '✕';
            remove.addEventListener('click', () => removeAttachment(item));
            chip.appendChild(remove);

            chips.appendChild(chip);

            // Расшифровку голосового показываем сразу: человек должен
            // видеть, что распозналось, до того как отправит.
            if (item.transcript) {
                const note = document.createElement('div');
                note.className = 'w-full rounded-lg bg-gray-50 px-3 py-1.5 text-xs italic text-gray-600';
                note.textContent = '«' + item.transcript + '»';
                chips.appendChild(note);
            }

            if (item.status === 'failed' && item.error) {
                const note = document.createElement('div');
                note.className = 'w-full rounded-lg bg-red-50 px-3 py-1.5 text-xs text-red-700';
                note.textContent = item.error;
                chips.appendChild(note);
            }
        });
    };

    const removeAttachment = async (item) => {
        attachments = attachments.filter((a) => a !== item);
        renderChips();

        if (item.id) {
            try {
                await fetch('/attachments/' + item.id, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                });
            } catch { /* файл подчистит фоновая уборка */ }
        }
    };

    const upload = async (file) => {
        if (attachments.length >= maxFiles) {
            showError('Больше ' + maxFiles + ' файлов к одному сообщению приложить нельзя.');
            return;
        }

        if (file.size > maxSize) {
            showError('Файл «' + file.name + '» слишком большой. Максимум — ' +
                Math.round(maxSize / 1048576) + ' МБ.');
            return;
        }

        const item = { name: file.name, status: 'uploading', icon: '📄' };
        attachments.push(item);
        renderChips();

        const body = new FormData();
        body.append('file', file);
        body.append('thread_id', form.dataset.thread);

        try {
            const response = await fetch(form.dataset.uploadUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                body,
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(data.message || 'Не удалось загрузить файл.');
            }

            Object.assign(item, data);

            // Файл загрузился, но разобрать его не вышло (не распозналась
            // речь, битый pdf) — показываем причину и не даём отправить мусор.
            if (data.status === 'failed') {
                item.status = 'failed';
                item.error = data.error || 'Файл не удалось прочитать.';
            }
        } catch (error) {
            item.status = 'failed';
            item.error = error.message;
        }

        renderChips();
    };

    const uploadAll = (files) => Array.from(files).forEach(upload);

    document.getElementById('attach-button').addEventListener('click', () => fileInput.click());

    fileInput.addEventListener('change', () => {
        uploadAll(fileInput.files);
        fileInput.value = '';
    });

    // Перетаскивание файлов в окно.
    ['dragenter', 'dragover'].forEach((type) => {
        document.addEventListener(type, (event) => {
            if (event.dataTransfer?.types?.includes('Files')) {
                event.preventDefault();
                form.classList.add('ring-2', 'ring-indigo-400', 'rounded-lg');
            }
        });
    });

    ['dragleave', 'drop'].forEach((type) => {
        document.addEventListener(type, (event) => {
            if (type === 'drop') event.preventDefault();
            if (type === 'dragleave' && event.relatedTarget) return;
            form.classList.remove('ring-2', 'ring-indigo-400', 'rounded-lg');
        });
    });

    document.addEventListener('drop', (event) => {
        if (event.dataTransfer?.files?.length) {
            event.preventDefault();
            uploadAll(event.dataTransfer.files);
        }
    });

    // Вставка картинки из буфера обмена (скриншот через Ctrl+V).
    input.addEventListener('paste', (event) => {
        const files = Array.from(event.clipboardData?.items ?? [])
            .filter((i) => i.kind === 'file')
            .map((i) => i.getAsFile())
            .filter(Boolean);

        if (files.length) {
            event.preventDefault();
            files.forEach(upload);
        }
    });

    /* -------------------------------------------------------------- */
    /*  Запись голоса                                                  */
    /* -------------------------------------------------------------- */

    const recordButton = document.getElementById('record-button');
    const recorderBox = document.getElementById('recorder');
    const recorderTime = document.getElementById('recorder-time');

    let recorder = null;
    let recorderChunks = [];
    let recorderTimer = null;
    let recorderCancelled = false;

    const micAvailable = window.isSecureContext && !!navigator.mediaDevices?.getUserMedia;

    if (!micAvailable) {
        // Браузеры дают микрофон только на https или на localhost.
        // С телефона по сети это обычная ситуация — объясняем прямо.
        recordButton.addEventListener('click', () => showError(
            'Запись голоса доступна только по HTTPS или на самом компьютере с приложением. ' +
            'Сейчас страница открыта по обычному HTTP. Можно приложить готовый аудиофайл кнопкой скрепки, ' +
            'а по поводу HTTPS обратитесь к администратору.'
        ));
    } else {
        const pickMime = () => ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg']
            .find((type) => MediaRecorder.isTypeSupported(type)) ?? '';

        const stopTracks = () => recorder?.stream.getTracks().forEach((track) => track.stop());

        const startRecording = async () => {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                const mime = pickMime();

                recorder = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
                recorderChunks = [];
                recorderCancelled = false;

                recorder.ondataavailable = (event) => {
                    if (event.data.size) recorderChunks.push(event.data);
                };

                recorder.onstop = () => {
                    stopTracks();
                    clearInterval(recorderTimer);
                    recorderBox.classList.add('hidden');
                    recorderBox.classList.remove('flex');
                    recordButton.classList.remove('bg-red-100', 'text-red-600');

                    if (recorderCancelled || !recorderChunks.length) return;

                    const type = recorder.mimeType || 'audio/webm';
                    const extension = type.includes('mp4') ? 'm4a' : type.includes('ogg') ? 'ogg' : 'webm';
                    const blob = new Blob(recorderChunks, { type });

                    upload(new File([blob], 'Голосовое сообщение.' + extension, { type }));
                };

                recorder.start();

                const startedAt = Date.now();
                recorderTime.textContent = '0:00';
                recorderTimer = setInterval(() => {
                    const seconds = Math.floor((Date.now() - startedAt) / 1000);
                    recorderTime.textContent = Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');

                    // Пятиминутную запись Whisper будет жевать очень долго.
                    if (seconds >= 300) document.getElementById('recorder-stop').click();
                }, 250);

                recorderBox.classList.remove('hidden');
                recorderBox.classList.add('flex');
                recordButton.classList.add('bg-red-100', 'text-red-600');
            } catch (error) {
                showError(error.name === 'NotAllowedError'
                    ? 'Браузер не дал доступ к микрофону. Разрешите его в настройках сайта.'
                    : 'Не удалось начать запись: ' + error.message);
            }
        };

        recordButton.addEventListener('click', () => {
            if (recorder?.state === 'recording') {
                recorder.stop();
            } else {
                startRecording();
            }
        });

        document.getElementById('recorder-stop').addEventListener('click', () => recorder?.stop());

        document.getElementById('recorder-cancel').addEventListener('click', () => {
            recorderCancelled = true;
            recorder?.stop();
        });
    }

    /* -------------------------------------------------------------- */
    /*  Отправка                                                       */
    /* -------------------------------------------------------------- */

    const setBusy = (busy) => {
        sending = busy;
        input.disabled = busy;
        sendButton.disabled = busy;
        thinking.classList.toggle('hidden', !busy);
    };

    /**
     * Живой пузырь ответа: статус, сворачиваемые мысли и текст,
     * который печатается по мере того, как модель его придумывает.
     */
    const createLiveBubble = () => {
        const wrap = document.createElement('div');
        wrap.className = 'flex justify-start';
        wrap.innerHTML = `
            <div class="max-w-[85%] rounded-2xl border border-gray-200 bg-white px-3.5 py-2.5 shadow-sm sm:max-w-3xl sm:px-4 sm:py-3">
                <div data-status class="flex items-center gap-2 text-xs text-gray-500">
                    <span class="inline-block h-3 w-3 animate-spin rounded-full border-2 border-gray-300 border-t-indigo-600"></span>
                    <span data-status-text>Думаю…</span>
                </div>
                <details data-thinking class="mt-2 hidden rounded-lg bg-gray-50 px-3 py-2">
                    <summary class="cursor-pointer text-xs text-gray-500 hover:text-gray-700">Ход мыслей</summary>
                    <div data-thinking-text class="mt-1 whitespace-pre-wrap text-xs leading-relaxed text-gray-500"></div>
                </details>
                <div data-answer class="prose prose-sm mt-2 hidden max-w-none whitespace-pre-wrap break-words"></div>
            </div>`;

        list.appendChild(wrap);

        return {
            statusText: wrap.querySelector('[data-status-text]'),
            status: wrap.querySelector('[data-status]'),
            thinking: wrap.querySelector('[data-thinking]'),
            thinkingText: wrap.querySelector('[data-thinking-text]'),
            answer: wrap.querySelector('[data-answer]'),
            root: wrap,
        };
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (sending) return;

        const text = input.value.trim();
        const ready = attachments.filter((a) => a.id && a.status !== 'uploading');

        if (attachments.some((a) => a.status === 'uploading')) {
            showError('Дождитесь, пока файлы загрузятся.');
            return;
        }

        if (text === '' && ready.length === 0) {
            showError('Напишите вопрос или приложите файл.');
            return;
        }

        setBusy(true);

        const payload = { message: text, attachments: ready.map((a) => a.id) };

        input.value = '';
        autoGrow();
        attachments = [];
        renderChips();

        const bubble = createLiveBubble();
        scrollDown();

        // Прокручиваем вниз, только если пользователь и так внизу —
        // иначе он не сможет перечитать написанное выше, пока идёт ответ.
        const atBottom = () => list.scrollHeight - list.scrollTop - list.clientHeight < 120;

        try {
            const response = await fetch(form.dataset.streamUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'text/event-stream',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
            });

            if (!response.ok || !response.body) {
                throw new Error('Сервер ответил ошибкой ' + response.status);
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            const handle = (event, data) => {
                const stick = atBottom();

                if (event === 'question') {
                    bubble.root.insertAdjacentHTML('beforebegin', data.html);
                } else if (event === 'status') {
                    bubble.statusText.textContent = data.text;
                } else if (event === 'thinking') {
                    bubble.thinking.classList.remove('hidden');
                    bubble.thinkingText.textContent += data.text;
                } else if (event === 'delta') {
                    bubble.status.classList.add('hidden');
                    bubble.answer.classList.remove('hidden');
                    bubble.answer.textContent += data.text;
                } else if (event === 'done') {
                    // Готовое сообщение приходит уже свёрстанным: с разметкой,
                    // источниками и вложениями. Заменяем им живой пузырь.
                    bubble.root.outerHTML = data.html;
                } else if (event === 'failed') {
                    bubble.status.classList.add('hidden');
                    bubble.answer.classList.remove('hidden');
                    bubble.answer.classList.add('text-red-600');
                    bubble.answer.textContent = data.message;
                }

                if (stick) scrollDown();
            };

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });

                // Одно событие — это блок строк до пустой строки.
                let split;
                while ((split = buffer.indexOf('\n\n')) !== -1) {
                    const raw = buffer.slice(0, split);
                    buffer = buffer.slice(split + 2);

                    let name = 'message';
                    let payloadText = '';

                    raw.split('\n').forEach((line) => {
                        if (line.startsWith('event:')) name = line.slice(6).trim();
                        else if (line.startsWith('data:')) payloadText += line.slice(5).trim();
                    });

                    if (payloadText === '') continue;

                    try {
                        handle(name, JSON.parse(payloadText));
                    } catch { /* битое событие пропускаем */ }
                }
            }
        } catch (error) {
            bubble.status.classList.add('hidden');
            bubble.answer.classList.remove('hidden');
            bubble.answer.classList.add('text-red-600');
            bubble.answer.textContent = 'Не удалось получить ответ: ' + error.message + '. Обновите страницу.';
        } finally {
            setBusy(false);
            if (!isTouch) input.focus();
            scrollDown();
        }
    });

    scrollDown();
}
