#!/usr/bin/env bash
#
# Обновление уже установленного помощника.
#
#   ./deploy.sh
#
# Что делает: забирает изменения из git, доустанавливает зависимости,
# накатывает миграции, перезапускает контейнеры и проверяет, что
# приложение отвечает. Если что-то пошло не так — говорит, что именно,
# и подсказывает, как откатиться.
#
# Запускать на том компьютере, где стоит приложение.
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$APP_DIR"

DC="docker compose"
EXEC="$DC exec -T app"

green()  { printf '\033[0;32m%s\033[0m\n' "$*"; }
yellow() { printf '\033[0;33m%s\033[0m\n' "$*"; }
red()    { printf '\033[0;31m%s\033[0m\n' "$*"; }
step()   { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }

# ---------------------------------------------------------------------------
#  Проверки перед началом
# ---------------------------------------------------------------------------

command -v docker >/dev/null || { red "Не найден docker."; exit 1; }
[ -f .env ] || { red "Нет файла .env. Похоже, приложение ещё не установлено — запустите deploy/install.sh"; exit 1; }
[ -d .git ] || { red "Это не git-репозиторий, обновлять нечем."; exit 1; }

# shellcheck disable=SC1091
set -a; source .env; set +a

PROFILES=""
[ "${LLM_DRIVER:-ollama}" = "ollama" ] && PROFILES="$PROFILES --profile with-ollama"
[ "${STT_ENABLED:-true}" = "true" ]    && PROFILES="$PROFILES --profile with-whisper"

step "Проверяем, нет ли несохранённых правок"

if [ -n "$(git status --porcelain)" ]; then
    red "В рабочей папке есть изменения — обновление остановлено, чтобы их не потерять."
    git status --short
    echo
    yellow "Если правки не нужны:  git checkout -- ."
    yellow "Если нужны:            git stash"
    exit 1
fi

# ---------------------------------------------------------------------------
#  Забираем изменения
# ---------------------------------------------------------------------------

step "Забираем изменения из git"

OLD_SHA="$(git rev-parse HEAD)"
git pull --ff-only
NEW_SHA="$(git rev-parse HEAD)"

if [ "$OLD_SHA" = "$NEW_SHA" ]; then
    green "Изменений нет — обновлять нечего."
    exit 0
fi

CHANGED="$(git diff --name-only "$OLD_SHA" "$NEW_SHA")"
changed() { echo "$CHANGED" | grep -qE "$1"; }

echo
echo "Изменённых файлов: $(echo "$CHANGED" | wc -l)"
git --no-pager log --oneline "$OLD_SHA".."$NEW_SHA" | head -10

# Откатываться будем на этот коммит, если развалится.
echo "$OLD_SHA" > .deploy-previous

# ---------------------------------------------------------------------------
#  Образы и контейнеры
# ---------------------------------------------------------------------------

if changed '^docker/|^docker-compose\.yml$'; then
    step "Изменился Docker — пересобираем образы"
    # shellcheck disable=SC2086
    $DC $PROFILES build
fi

step "Поднимаем контейнеры"
# shellcheck disable=SC2086
$DC $PROFILES up -d --remove-orphans

# ---------------------------------------------------------------------------
#  Зависимости
# ---------------------------------------------------------------------------

if changed 'composer\.(json|lock)$' || [ ! -d vendor ]; then
    step "Доустанавливаем зависимости PHP"

    if ! $EXEC composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader; then
        red "Composer не отработал."
        yellow "Чаще всего причина одна: на этом компьютере нет доступа в интернет,"
        yellow "а обновление требует новых библиотек."
        yellow "Решение: скопируйте папку vendor с машины разработчика и запустите deploy.sh ещё раз."
        exit 1
    fi
else
    echo "Зависимости PHP не менялись — пропускаем."
fi

# Стили и скрипты собираются заранее и приходят в репозитории:
# в образе php нет Node, а на моноблоке может не быть интернета.
if changed '^resources/(js|css)/|^vite\.config\.|^tailwind\.config\.' && ! changed '^public/build/'; then
    yellow "Внимание: изменились исходники стилей или скриптов, но собранные файлы"
    yellow "(public/build) не обновились. Скорее всего, разработчик забыл выполнить"
    yellow "make assets и закоммитить результат. Интерфейс может выглядеть сломанным."
fi

# ---------------------------------------------------------------------------
#  Laravel
# ---------------------------------------------------------------------------

step "Чистим кэш"
$EXEC php artisan optimize:clear

step "Накатываем миграции базы"
$EXEC php artisan migrate --force

if [ "${APP_ENV:-production}" = "production" ]; then
    step "Прогреваем кэш"
    $EXEC php artisan config:cache
    $EXEC php artisan route:cache
    $EXEC php artisan view:cache
fi

$EXEC php artisan storage:link 2>/dev/null || true

# ---------------------------------------------------------------------------
#  Перезапуск
# ---------------------------------------------------------------------------

# В боевом режиме opcache держит старый код в памяти: без перезапуска
# контейнеров обновление просто не вступит в силу.
step "Перезапускаем приложение и фоновые процессы"
$EXEC php artisan queue:restart || true
# shellcheck disable=SC2086
$DC $PROFILES restart app worker scheduler nginx

# ---------------------------------------------------------------------------
#  Проверка, что всё живо
# ---------------------------------------------------------------------------

step "Проверяем, что приложение отвечает"

CODE=""
for _ in 1 2 3 4 5 6 7 8 9 10; do
    sleep 3
    CODE="$($DC exec -T nginx sh -lc 'curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1/up || true')"
    [ "$CODE" = "200" ] || [ "$CODE" = "204" ] && break
done

if [ "$CODE" != "200" ] && [ "$CODE" != "204" ]; then
    red "Приложение не отвечает (код: ${CODE:-нет ответа})."
    echo
    yellow "Последние строки логов:"
    $DC logs --tail=40 app nginx || true
    echo
    yellow "Откатиться на прошлую версию:"
    yellow "  git reset --hard $OLD_SHA && ./deploy.sh --force"
    exit 1
fi

step "Проверяем состояние сервисов"
$EXEC php artisan aihub:diagnose || yellow "Диагностика нашла замечания — посмотрите список выше."

echo
green "Готово. Обновлено до $(git rev-parse --short HEAD)."
green "Адрес: ${APP_URL:-http://localhost:8080}"
