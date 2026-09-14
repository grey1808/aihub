#!/usr/bin/env bash
#
# Полная подготовка к работе одной командой.
#
#   ./deploy/setup.sh
#
# Поднимает всё нужное по настройкам из .env, докачивает недостающие
# модели, прогревает их и показывает состояние. Повторный запуск безопасен.
#
set -uo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

green()  { printf '\033[0;32m%s\033[0m\n' "$*"; }
yellow() { printf '\033[0;33m%s\033[0m\n' "$*"; }
step()   { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }

[ -f .env ] || { cp .env.example .env; green "Создан .env из примера."; }

# shellcheck disable=SC1091
set -a; source .env; set +a

# --- какие профили и файлы нужны при этих настройках ---------------------

FILES="-f docker-compose.yml"
PROFILES=""

# Контейнер с Ollama поднимаем, только если адреса указывают именно на него.
# Ollama, поставленная прямо в систему, живёт по адресу host.docker.internal —
# тогда контейнер не нужен и запускать его незачем.
NEED_OLLAMA=no
case "${LLM_BASE_URL:-}" in *//ollama:*) NEED_OLLAMA=yes ;; esac
case "${LLM_EMBEDDING_BASE_URL:-}" in *//ollama:*) NEED_OLLAMA=yes ;; esac

# Адрес не задан вовсе — значит действует значение по умолчанию, а оно
# указывает на контейнер.
[ -z "${LLM_BASE_URL:-}" ] && [ "${LLM_DRIVER:-ollama}" = "ollama" ] && NEED_OLLAMA=yes

[ "$NEED_OLLAMA" = yes ] && PROFILES="$PROFILES --profile with-ollama"
[ "${STT_ENABLED:-true}" = "true" ] && PROFILES="$PROFILES --profile with-whisper"

# Видеокарта: make up GPU=amd или GPU=amd в .env
if [ "${GPU:-}" = "amd" ]; then
    FILES="$FILES -f docker-compose.gpu.yml"
    green "Ollama будет запущена с доступом к видеокарте AMD."
fi

DC="docker compose $FILES $PROFILES"

step "Поднимаем сервисы"
# shellcheck disable=SC2086
$DC up -d --remove-orphans || exit 1

# --- модели ---------------------------------------------------------------

if [ "$NEED_OLLAMA" = yes ]; then
    step "Проверяем модели"

    # Ollama после старта отвечает не сразу.
    for _ in $(seq 1 30); do
        $DC exec -T ollama ollama list >/dev/null 2>&1 && break
        sleep 2
    done

    INSTALLED="$($DC exec -T ollama ollama list 2>/dev/null | tail -n +2 | awk '{print $1}')"

    pull_if_missing() {
        local model="$1" what="$2"
        [ -z "$model" ] && return 0

        # Ollama показывает модели с тегом, а в .env его часто не пишут.
        if echo "$INSTALLED" | grep -qE "^${model}(:latest)?$"; then
            echo "  $what «$model» — уже есть"
            return 0
        fi

        yellow "  $what «$model» — качаем, это долго"
        $DC exec -T ollama ollama pull "$model" || yellow "  не удалось скачать «$model»"
    }

    case "${LLM_BASE_URL:-}" in
        *//ollama:*|"") pull_if_missing "${LLM_MODEL:-}" "модель для диалога" ;;
    esac

    case "${LLM_EMBEDDING_BASE_URL:-}" in
        *//ollama:*|"") pull_if_missing "${LLM_EMBEDDING_MODEL:-}" "модель для векторов" ;;
    esac

    pull_if_missing "${LLM_VISION_MODEL:-}" "модель со зрением"
fi

# --- приложение -----------------------------------------------------------

if [ "$NEED_OLLAMA" = no ]; then
    yellow "Нейросеть запущена вне контейнеров — контейнер ollama не поднимаем."
    yellow "Модели в неё нужно докачивать самостоятельно, например:"
    yellow "  ollama pull ${LLM_MODEL:-<модель>}"
fi

step "Прогреваем модели"
$DC exec -T app php artisan aihub:warmup --quiet-fail || true

# Полная проверка спрашивает модель по-настоящему, а это десятки секунд.
# При каждом запуске столько ждать незачем — включается по требованию:
#   make up CHECK=1
CODE=0

if [ "${CHECK:-}" = "1" ]; then
    step "Проверяем состояние"
    $DC exec -T app php artisan aihub:diagnose
    CODE=$?
fi

echo
if [ $CODE -eq 0 ]; then
    green "Готово. Помощник доступен: ${APP_URL:-http://localhost:8080}"
    green "Вход администратора: ${ADMIN_LOGIN:-admin} / ${ADMIN_PASSWORD:-admin}"

    [ "${CHECK:-}" = "1" ] || echo "Полная проверка при необходимости: make diagnose"
else
    yellow "Есть незакрытые пункты — смотрите раздел «Итог» выше."
fi

exit $CODE
