#!/usr/bin/env bash
#
# Проверка компьютера перед установкой ИИ-помощника.
#
#   bash check-machine.sh
#
# Скрипт ничего не устанавливает и ничего не меняет — только смотрит
# и собирает отчёт в файл. Запускать можно без прав администратора
# (тогда часть проверок будет пропущена, это нормально).
#
# Результат: файл aihub-check-<дата>.txt рядом со скриптом.
# Его нужно отправить разработчику.
#
# Если скрипт запустили через sh, перезапускаем себя в bash:
# ниже используются возможности, которых в sh нет.
if [ -z "${BASH_VERSION:-}" ]; then
    exec bash "$0" "$@"
fi

set -uo pipefail

# Отчёт кладём туда, куда получится записать: текущая папка бывает
# только для чтения, и тогда скрипт молча падал бы в самом начале.
for dir in "$PWD" "$HOME" /tmp; do
    if [ -w "$dir" ]; then
        REPORT="$dir/aihub-check-$(date +%Y%m%d-%H%M).txt"
        break
    fi
done

: > "$REPORT" 2>/dev/null || { echo "Не удалось создать файл отчёта."; exit 1; }

say()  { echo "$*" | tee -a "$REPORT"; }
head2() { say ""; say "=== $* ==="; }
have() { command -v "$1" >/dev/null 2>&1; }

# Выполнить команду и показать её вывод — и на экран, и в отчёт.
run() { "$@" 2>/dev/null | tee -a "$REPORT"; }

say "Проверка компьютера для ИИ-помощника"
say "Дата: $(date '+%d.%m.%Y %H:%M')"
say "Компьютер: $(hostname 2>/dev/null || echo '—')"
say "Пользователь: $(whoami) (uid $(id -u))"

# ---------------------------------------------------------------------------
head2 "Операционная система"

if [ -r /etc/os-release ]; then
    . /etc/os-release
    say "Система: ${PRETTY_NAME:-$NAME $VERSION}"
else
    say "Система: не определилась (нет /etc/os-release)"
fi

say "Ядро: $(uname -sr)"
say "Архитектура: $(uname -m)"

if [ -n "${XDG_SESSION_TYPE:-}${DISPLAY:-}" ]; then
    say "Графическая оболочка: есть (${XDG_SESSION_TYPE:-x11})"
else
    say "Графическая оболочка: не обнаружена (сервер без рабочего стола)"
fi

if have systemctl; then
    say "Режим загрузки: $(systemctl get-default 2>/dev/null || echo '-')"
fi

# Подключён ли монитор. Для мини-ПК это не праздный вопрос: приложения
# с окном (LM Studio) без графической сессии могут не запуститься,
# а сессия без монитора поднимается не всегда.
CONNECTED=""
for out in /sys/class/drm/card*-*/status; do
    [ -r "$out" ] || continue
    if [ "$(cat "$out" 2>/dev/null)" = "connected" ]; then
        CONNECTED="$CONNECTED $(basename "$(dirname "$out")")"
    fi
done

if [ -n "$CONNECTED" ]; then
    say "Подключённые мониторы:$CONNECTED"
else
    say "Мониторы: не подключены (машина работает без экрана)"
    say "  Тогда LM Studio нужно запускать без окна: lms server start"
fi

if have systemctl; then
    say "systemd: есть (автозапуск настроить можно)"
    LINGER="$(loginctl show-user "$(whoami)" -p Linger --value 2>/dev/null)"
    say "Служба пользователя работает без входа в систему (linger): ${LINGER:-неизвестно}"
else
    say "systemd: НЕТ — автозапуск при включении придётся делать иначе"
fi

# ---------------------------------------------------------------------------
head2 "Процессор и память"

if have lscpu; then
    say "Процессор: $(lscpu | awk -F: '/Model name/ {gsub(/^ +/,"",$2); print $2; exit}')"
    say "Потоков: $(lscpu | awk -F: '/^CPU\(s\)/ {gsub(/^ +/,"",$2); print $2; exit}')"
else
    say "Процессор: $(awk -F: '/model name/ {gsub(/^ +/,"",$2); print $2; exit}' /proc/cpuinfo 2>/dev/null || echo '—')"
fi

if [ -r /proc/meminfo ]; then
    TOTAL_KB=$(awk '/MemTotal/ {print $2}' /proc/meminfo)
    AVAIL_KB=$(awk '/MemAvailable/ {print $2}' /proc/meminfo)
    say "Оперативная память, видимая системе: $((TOTAL_KB / 1024 / 1024)) ГБ"
    say "Из неё свободно: $((AVAIL_KB / 1024 / 1024)) ГБ"
    say ""
    say "ВАЖНО: если здесь заметно меньше, чем физически установлено,"
    say "значит часть памяти отдана видеоядру (UMA). Это нормально."
fi

if have swapon; then
    say "Подкачка: $(swapon --show=SIZE --noheadings 2>/dev/null | tr '\n' ' ' || echo 'нет')"
fi

# ---------------------------------------------------------------------------
head2 "Видеоядро и видеопамять"

FOUND_GPU=no

for card in /sys/class/drm/card*/device; do
    [ -r "$card/uevent" ] || continue

    NAME=$(grep -m1 '^DRIVER=' "$card/uevent" 2>/dev/null | cut -d= -f2)
    [ -n "$NAME" ] || continue

    FOUND_GPU=yes
    say "Драйвер: $NAME"

    if [ -r "$card/mem_info_vram_total" ]; then
        VRAM=$(cat "$card/mem_info_vram_total")
        say "Видеопамяти выделено: $((VRAM / 1024 / 1024 / 1024)) ГБ"
    fi

    if [ -r "$card/mem_info_vram_used" ]; then
        USED=$(cat "$card/mem_info_vram_used")
        say "Из них занято: $((USED / 1024 / 1024 / 1024)) ГБ"
    fi
done

[ "$FOUND_GPU" = yes ] || say "Видеоядро через sysfs не определилось."

have nvidia-smi && say "NVIDIA: $(nvidia-smi --query-gpu=name,memory.total --format=csv,noheader 2>/dev/null | head -1)"

# ---------------------------------------------------------------------------
head2 "Место на диске"

df -h / /home /var/lib/docker 2>/dev/null | awk 'NR==1 || !seen[$NF]++' | tee -a "$REPORT"

say ""
say "Нужно не меньше 60 ГБ свободных: образы и модели весят много."

# ---------------------------------------------------------------------------
head2 "Docker"

if have docker; then
    say "docker: $(docker --version 2>/dev/null)"

    if docker compose version >/dev/null 2>&1; then
        say "docker compose: $(docker compose version --short 2>/dev/null)"
    else
        say "docker compose: НЕТ плагина v2 — нужно доустановить"
    fi

    if docker info >/dev/null 2>&1; then
        say "Служба docker: работает, текущий пользователь имеет доступ"
    elif sudo -n docker info >/dev/null 2>&1; then
        say "Служба docker: работает, но нужен sudo (пользователь не в группе docker)"
    else
        say "Служба docker: не отвечает или нет прав. Проверьте: sudo systemctl status docker"
    fi
else
    say "docker: НЕ УСТАНОВЛЕН — это обязательное требование"
fi

# ---------------------------------------------------------------------------
head2 "LM Studio"

LMS_FOUND=no

for port in 1234 1235; do
    ANSWER=$(curl -s --max-time 5 "http://127.0.0.1:${port}/v1/models" 2>/dev/null)

    if [ -n "$ANSWER" ]; then
        LMS_FOUND=yes
        say "Сервер отвечает на порту ${port}."
        say "Загруженные модели (это имя нужно указать в настройках):"
        echo "$ANSWER" | grep -o '"id"[[:space:]]*:[[:space:]]*"[^"]*"' | cut -d'"' -f4 | sed 's/^/  - /' | tee -a "$REPORT"
        say ""
        say "Если моделей несколько, укажите ту, что должна отвечать в чате."
        break
    fi
done

if [ "$LMS_FOUND" = no ]; then
    say "Сервер LM Studio на портах 1234 и 1235 не отвечает."
    say "Возможные причины: не запущен, выключен локальный сервер в настройках,"
    say "или слушает другой порт."
fi

if have lms; then
    say "Утилита lms: есть — автозапуск без окна настроить можно"
    say "Состояние сервера: $(lms server status 2>&1 | head -2 | tr '\n' ' ')"
else
    say "Утилита lms: НЕТ. Ставится из самой LM Studio, нужна для запуска без окна."
fi

pgrep -a llama-server 2>/dev/null | head -2 | sed 's/^/  процесс: /' | tee -a "$REPORT"

# ---------------------------------------------------------------------------
head2 "Порты"

check_port() {
    if have ss; then
        ss -ltn 2>/dev/null | awk '{print $4}' | grep -qE "[:.]$1\$" && echo занят || echo свободен
    elif have netstat; then
        netstat -ltn 2>/dev/null | awk '{print $4}' | grep -qE "[:.]$1\$" && echo занят || echo свободен
    else
        echo "проверить нечем"
    fi
}

for p in 80 443 8080 8443 5432 6379 11434 1234; do
    say "  порт $p: $(check_port $p)"
done

say ""
say "Приложению нужны два свободных порта: один под сайт (обычно 8080)"
say "и один под защищённое соединение (обычно 8443)."

# ---------------------------------------------------------------------------
head2 "Сеть"

IPS=$(hostname -I 2>/dev/null || ip -4 addr show scope global 2>/dev/null | awk '/inet/ {print $2}' | cut -d/ -f1 | tr '\n' ' ')
say "Адреса компьютера в сети: ${IPS:-не определились}"
say "По одному из них сотрудники будут открывать помощника."
say ""

# На этой машине два проводных порта разной скорости — полезно знать,
# в какой из них она включена.
for iface in /sys/class/net/*; do
    name=$(basename "$iface")
    [ "$name" = "lo" ] && continue
    [ -r "$iface/operstate" ] || continue
    [ "$(cat "$iface/operstate" 2>/dev/null)" = "up" ] || continue

    # Только настоящие сетевые карты: у виртуальных интерфейсов докера
    # и мостов нет ссылки на устройство, и в списке они только мешают.
    [ -e "$iface/device" ] || continue

    SPEED=$(cat "$iface/speed" 2>/dev/null)
    ADDR=$(ip -4 addr show "$name" 2>/dev/null | awk '/inet /{print $2; exit}')

    if [ -n "$SPEED" ] && [ "$SPEED" != "-1" ]; then
        say "  $name: подключён, ${SPEED} Мбит/с, адрес ${ADDR:-нет}"
    else
        say "  $name: подключён, адрес ${ADDR:-нет}"
    fi
done

for target in "https://github.com" "https://registry-1.docker.io" "https://ollama.com"; do
    if curl -s --max-time 8 -o /dev/null -w "%{http_code}" "$target" 2>/dev/null | grep -qE '^[23]'; then
        say "  доступ к $target: есть"
    else
        say "  доступ к $target: НЕТ"
    fi
done

say ""
say "Если доступа в интернет нет совсем — установка возможна,"
say "но образы и модели придётся принести на носителе."

# ---------------------------------------------------------------------------
head2 "Питание"

say "Для сценария «включили — всё работает» важно, чтобы компьютер сам"
say "включался после пропадания электричества. Настройка называется"
say "Restore on AC Power Loss (или AC Back, After Power Failure) и живёт"
say "в BIOS — из системы её не увидеть. Проверьте, что она в положении Power On."
say ""
say "Если есть источник бесперебойного питания — напишите какой,"
say "чтобы настроить корректное выключение при долгом отсутствии света."

head2 "Права"

if sudo -n true 2>/dev/null; then
    say "sudo без пароля: есть"
elif have sudo; then
    say "sudo: установлен (пароль запрашивается)"
else
    say "sudo: НЕТ — установка потребует прав администратора"
fi

# ---------------------------------------------------------------------------
say ""
say "==================================================="
say "Проверка закончена."
say "Отчёт сохранён в файл: $REPORT"
say "Отправьте этот файл разработчику."
say "==================================================="
