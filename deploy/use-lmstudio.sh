#!/usr/bin/env bash
#
# Переключение диалога на LM Studio.
#
#   ./deploy/use-lmstudio.sh
#
# Зачем: LM Studio уже работает с видеокартой, и это самый быстрый путь
# к рабочему помощнику. Вектора при этом остаются на контейнерной Ollama —
# модель для них маленькая, процессора ей хватает, и друг другу они
# не мешают.
#
# Права root не нужны.
#
set -uo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

green()  { printf '\033[0;32m%s\033[0m\n' "$*"; }
yellow() { printf '\033[0;33m%s\033[0m\n' "$*"; }
red()    { printf '\033[0;31m%s\033[0m\n' "$*"; }
step()   { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }

[ -f .env ] || { red "Нет файла .env — запустите из папки проекта."; exit 1; }

PORT="${1:-1234}"

# ---------------------------------------------------------------------------
step "Шаг 1. Ищем сервер LM Studio"

ANSWER=""
for try_port in "$PORT" 1234 1235; do
    ANSWER="$(curl -s --max-time 5 "http://127.0.0.1:${try_port}/v1/models" 2>/dev/null)"

    if [ -n "$ANSWER" ]; then
        PORT="$try_port"
        break
    fi
done

if [ -z "$ANSWER" ]; then
    red "Сервер LM Studio не отвечает на портах ${PORT}, 1234, 1235."
    echo
    yellow "Проверьте в LM Studio:"
    yellow "  1. Вкладка Developer → переключатель сервера включён (Status: Running)"
    yellow "  2. Модель загружена (Loaded models — не пусто)"
    exit 1
fi

green "Сервер отвечает на порту ${PORT}."

# ---------------------------------------------------------------------------
step "Шаг 2. Выбираем модель"

# Имена моделей в LM Studio длинные, и набирать их руками — верный способ
# ошибиться на одном символе. Берём точные значения из самого ответа.
mapfile -t MODELS < <(echo "$ANSWER" | grep -o '"id"[[:space:]]*:[[:space:]]*"[^"]*"' | cut -d'"' -f4)

if [ ${#MODELS[@]} -eq 0 ]; then
    red "Сервер отвечает, но ни одной модели не загружено."
    yellow "Загрузите модель в LM Studio и повторите."
    exit 1
fi

for i in "${!MODELS[@]}"; do
    printf '  %d) %s\n' "$((i + 1))" "${MODELS[$i]}"
done

if [ ${#MODELS[@]} -eq 1 ]; then
    CHOICE=1
    echo
    green "Модель одна, берём её."
else
    printf '\nНомер модели для диалога [1]: '
    read -r CHOICE </dev/tty
    CHOICE="${CHOICE:-1}"
fi

MODEL="${MODELS[$((CHOICE - 1))]:-}"
[ -n "$MODEL" ] || { red "Неверный номер."; exit 1; }

green "Выбрана: $MODEL"

# ---------------------------------------------------------------------------
step "Шаг 3. Проверяем, видна ли LM Studio из контейнера"

# Контейнер обращается к хосту не по localhost, а по адресу шлюза.
# Если в LM Studio не включено «Serve on Local Network», она слушает
# только себя, и приложение до неё не достучится — при этом снаружи
# всё выглядит рабочим.
GATEWAY="$(ip route | awk '/^default/ {print $3; exit}')"
HOST_IP="$(hostname -I | awk '{print $1}')"

REACHABLE=no
for addr in "$HOST_IP" "$GATEWAY" "172.17.0.1"; do
    [ -n "$addr" ] || continue

    if curl -s --max-time 4 "http://${addr}:${PORT}/v1/models" >/dev/null 2>&1; then
        REACHABLE=yes
        green "Доступна по сетевому адресу ${addr} — контейнер достучится."
        break
    fi
done

if [ "$REACHABLE" = no ]; then
    red "LM Studio слушает только localhost."
    echo
    yellow "Включите в LM Studio: Developer → Serve on Local Network."
    yellow "Без этого приложение из контейнера до неё не достучится."
    echo
    printf 'Продолжить всё равно? [y/N]: '
    read -r answer </dev/tty
    [ "$answer" = "y" ] || [ "$answer" = "Y" ] || exit 1
fi

# ---------------------------------------------------------------------------
step "Шаг 4. Правим .env"

cp .env ".env.backup-$(date +%Y%m%d-%H%M)"

set_env() {
    local key="$1" value="$2"

    if grep -qE "^${key}=" .env; then
        # Разделитель | вместо /: в значениях есть адреса со слэшами.
        sed -i "s|^${key}=.*|${key}=${value}|" .env
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}

set_env LLM_DRIVER lmstudio
set_env LLM_BASE_URL "http://host.docker.internal:${PORT}/v1"
set_env LLM_API_KEY lm-studio
set_env LLM_MODEL "$MODEL"

# Вектора оставляем на контейнерной Ollama: bge-m3 там уже скачана,
# она маленькая и на процессоре работает быстро.
set_env LLM_EMBEDDING_BASE_URL "http://ollama:11434/v1"

green "Готово. Прежний .env сохранён рядом."
echo
grep -E '^(LLM_DRIVER|LLM_BASE_URL|LLM_MODEL|LLM_EMBEDDING_BASE_URL|LLM_EMBEDDING_MODEL|LLM_EMBEDDING_DIMENSIONS)=' .env | sed 's/^/    /'

# ---------------------------------------------------------------------------
step "Шаг 5. Поднимаем и проверяем"

make up

echo
yellow "Если всё зелёное — проверьте, умеет ли модель вызывать инструменты:"
yellow "    make bench"
yellow "Доля ниже 80% означает, что помощник не будет находить документы."
