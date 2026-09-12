#!/usr/bin/env bash
#
# Установка ИИ-помощника на компьютер.
#
#   sudo ./deploy/install.sh
#
# Что делает:
#   1. Проверяет докер.
#   2. Создаёт .env, если его ещё нет.
#   3. Поднимает приложение.
#   4. Скачивает модели в Ollama (нужен интернет — один раз, при установке).
#   5. Если в .env стоит AUTOSTART_ON_BOOT=true — прописывает автозапуск
#      при включении компьютера через systemd.
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

green() { printf '\033[0;32m%s\033[0m\n' "$*"; }
yellow() { printf '\033[0;33m%s\033[0m\n' "$*"; }
red() { printf '\033[0;31m%s\033[0m\n' "$*"; }

command -v docker >/dev/null || { red "Не найден docker. Установите его и повторите."; exit 1; }
docker compose version >/dev/null 2>&1 || { red "Не найден docker compose (плагин v2)."; exit 1; }

if [ ! -f .env ]; then
    cp .env.example .env
    sed -i "s/^WWWUSER=.*/WWWUSER=$(id -u "${SUDO_USER:-$USER}")/" .env
    sed -i "s/^WWWGROUP=.*/WWWGROUP=$(id -g "${SUDO_USER:-$USER}")/" .env
    green "Создан .env — проверьте настройки перед боевым запуском."
fi

# shellcheck disable=SC1091
set -a; source .env; set +a

PROFILES=""
if [ "${LLM_DRIVER:-ollama}" = "ollama" ]; then
    PROFILES="--profile with-ollama"
fi

green "Собираем и запускаем контейнеры..."
# shellcheck disable=SC2086
docker compose $PROFILES up -d --build --remove-orphans

if [ -z "${APP_KEY:-}" ]; then
    docker compose exec -T app php artisan key:generate --force
fi

if [ "${LLM_DRIVER:-ollama}" = "ollama" ]; then
    green "Скачиваем модели. Это единственный шаг, которому нужен интернет."
    yellow "Модель ${LLM_MODEL} весит десятки гигабайт — наберитесь терпения."
    docker compose exec -T ollama ollama pull "${LLM_MODEL}"
    docker compose exec -T ollama ollama pull "${LLM_EMBEDDING_MODEL}"
fi

green "Проверяем, что всё на месте..."
docker compose exec -T app php artisan aihub:diagnose || true

if [ "${AUTOSTART_ON_BOOT:-false}" = "true" ]; then
    if [ "$(id -u)" -ne 0 ]; then
        red "Для автозапуска нужны права root. Перезапустите: sudo ./deploy/install.sh"
        exit 1
    fi

    green "Настраиваем автозапуск при включении компьютера..."

    sed -e "s|__APP_DIR__|${APP_DIR}|g" \
        -e "s|__COMPOSE_PROFILES__|${PROFILES}|g" \
        deploy/aihub.service > /etc/systemd/system/aihub.service

    systemctl daemon-reload
    systemctl enable aihub.service
    green "Готово: приложение будет подниматься само при каждом включении."
else
    yellow "Автозапуск выключен (AUTOSTART_ON_BOOT=false в .env)."
    yellow "Запускать вручную: docker compose ${PROFILES} up -d"
fi

echo
green "Помощник доступен по адресу: ${APP_URL:-http://localhost:8080}"
green "Вход администратора: ${ADMIN_LOGIN:-admin} / ${ADMIN_PASSWORD:-admin}"
yellow "Смените пароль администратора до того, как дадите доступ сотрудникам."
