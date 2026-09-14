#!/usr/bin/env bash
#
# Переезд на Ollama, установленную прямо в систему.
#
#   sudo ./deploy/use-host-ollama.sh
#
# Зачем: Ollama в контейнере не видит видеокарту и считает процессором.
# Для модели на 30 миллиардов параметров это минуты на один вопрос.
# Установленная в систему Ollama сама разбирается с драйверами.
#
# Скрипт по шагам показывает, что собирается сделать, и спрашивает
# подтверждение. Ничего не удаляет: старый том с моделями остаётся на месте.
#
set -uo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

green()  { printf '\033[0;32m%s\033[0m\n' "$*"; }
yellow() { printf '\033[0;33m%s\033[0m\n' "$*"; }
red()    { printf '\033[0;31m%s\033[0m\n' "$*"; }
step()   { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }

ask() {
    printf '\n%s [y/N]: ' "$1"
    read -r answer </dev/tty
    [ "$answer" = "y" ] || [ "$answer" = "Y" ]
}

[ "$(id -u)" -eq 0 ] || { red "Нужны права root: sudo $0"; exit 1; }
[ -f .env ] || { red "Нет файла .env — запустите из папки проекта."; exit 1; }

# Команды docker и правка .env должны выполняться от обычного пользователя,
# иначе файлы проекта станут принадлежать root.
OWNER="${SUDO_USER:-$(stat -c %U .env)}"
as_owner() { sudo -u "$OWNER" "$@"; }

# ---------------------------------------------------------------------------
step "Шаг 1. Ollama в системе"

if command -v ollama >/dev/null 2>&1; then
    green "Уже установлена: $(ollama --version 2>/dev/null | head -1)"
else
    yellow "Ollama в системе не найдена."
    yellow "Установка скачает около 1 ГБ и требует интернета."

    ask "Установить сейчас?" || { red "Без неё дальше нельзя. Останавливаюсь."; exit 1; }

    curl -fsSL https://ollama.com/install.sh | sh || { red "Установка не удалась."; exit 1; }
fi

# ---------------------------------------------------------------------------
step "Шаг 2. Настройки службы"

OVERRIDE_DIR=/etc/systemd/system/ollama.service.d
cat <<'CONF' > /tmp/aihub-ollama-override.conf
[Service]
# Слушать сеть, а не только саму себя: иначе контейнеры приложения
# до Ollama не достучатся, хотя адрес будет выглядеть правильным.
Environment="OLLAMA_HOST=0.0.0.0:11434"

# Не выгружать модель из памяти: иначе первый сотрудник после паузы
# ждёт её загрузку заново, а для большой модели это минута.
Environment="OLLAMA_KEEP_ALIVE=-1"

# Окно контекста. По умолчанию 4096 — найденные документы туда
# не помещаются и молча обрезаются.
Environment="OLLAMA_CONTEXT_LENGTH=16384"
CONF

echo "Будет создан файл ${OVERRIDE_DIR}/aihub.conf:"
echo
sed 's/^/    /' /tmp/aihub-ollama-override.conf

if ask "Применить эти настройки?"; then
    mkdir -p "$OVERRIDE_DIR"
    cp /tmp/aihub-ollama-override.conf "$OVERRIDE_DIR/aihub.conf"
    systemctl daemon-reload
    systemctl restart ollama
    sleep 3
    green "Настройки применены, служба перезапущена."
else
    yellow "Пропущено. Учтите: без OLLAMA_HOST приложение до Ollama не достучится."
fi

# ---------------------------------------------------------------------------
step "Шаг 3. Модели, уже скачанные в контейнер"

VOLUME_PATH="$(docker volume inspect aihub_ollama-data --format '{{.Mountpoint}}' 2>/dev/null)"

# Куда складывает модели системная Ollama: у службы свой домашний каталог.
HOST_MODELS=/usr/share/ollama/.ollama/models
[ -d /usr/share/ollama/.ollama ] || HOST_MODELS="${HOME}/.ollama/models"

if [ -n "$VOLUME_PATH" ] && [ -d "$VOLUME_PATH/models" ]; then
    SIZE="$(du -sh "$VOLUME_PATH/models" 2>/dev/null | cut -f1)"
    yellow "В контейнерном томе лежат модели на ${SIZE}."
    echo "Их можно скопировать в системную Ollama и не качать заново."
    echo "Копирование: из $VOLUME_PATH/models в $HOST_MODELS"

    if ask "Скопировать?"; then
        mkdir -p "$HOST_MODELS"
        cp -an "$VOLUME_PATH/models/." "$HOST_MODELS/" && green "Скопировано."

        # Файлы должны принадлежать пользователю, под которым работает служба.
        if id ollama >/dev/null 2>&1; then
            chown -R ollama:ollama "$(dirname "$HOST_MODELS")"
        fi

        systemctl restart ollama 2>/dev/null || true
        sleep 3
    fi
else
    yellow "Контейнерный том с моделями не найден — пропускаем."
fi

echo
echo "Сейчас системной Ollama доступны:"
ollama list 2>/dev/null | sed 's/^/    /' || yellow "  не удалось получить список"

# ---------------------------------------------------------------------------
step "Шаг 4. Переключаем приложение"

CURRENT="$(grep -E '^LLM_BASE_URL=' .env | head -1)"
echo "Сейчас в .env: ${CURRENT:-(не задано)}"
echo "Станет:        LLM_BASE_URL=http://host.docker.internal:11434/v1"

if ask "Поменять?"; then
    as_owner cp .env ".env.backup-$(date +%Y%m%d-%H%M)"

    if grep -qE '^LLM_BASE_URL=' .env; then
        as_owner sed -i 's|^LLM_BASE_URL=.*|LLM_BASE_URL=http://host.docker.internal:11434/v1|' .env
    else
        echo 'LLM_BASE_URL=http://host.docker.internal:11434/v1' | as_owner tee -a .env >/dev/null
    fi

    # Вектора тоже считает системная Ollama — контейнер больше не нужен.
    if grep -qE '^LLM_EMBEDDING_BASE_URL=' .env; then
        as_owner sed -i 's|^LLM_EMBEDDING_BASE_URL=.*|LLM_EMBEDDING_BASE_URL=|' .env
    fi

    green "Готово, старый .env сохранён рядом."
fi

# ---------------------------------------------------------------------------
step "Шаг 5. Останавливаем контейнер с Ollama"

if as_owner docker compose ps --services 2>/dev/null | grep -qx ollama; then
    as_owner docker compose stop ollama >/dev/null 2>&1
    green "Контейнер остановлен. Том с моделями не тронут — можно вернуться назад."
else
    echo "Контейнер не запущен, пропускаем."
fi

# ---------------------------------------------------------------------------
step "Шаг 6. Поднимаем приложение и проверяем"

as_owner make up

echo
green "Если в разделе «Чем считается модель» написано «видеокартой» — всё получилось."
yellow "Если «ПРОЦЕССОРОМ» — видеокарта не подхватилась. Проверьте: ollama ps"
yellow "В колонке PROCESSOR должно быть GPU. Дальнейшие шаги — в README,"
yellow "раздел «Модель считается процессором»."
