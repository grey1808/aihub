#!/usr/bin/env bash
#
# Самоподписанный сертификат для работы по HTTPS во внутренней сети.
#
#   ./deploy/make-cert.sh 192.168.1.50 monoblok.local
#
# Первый аргумент — адрес, по которому сотрудники открывают помощника.
# Он попадает в сертификат, иначе браузер будет ругаться ещё и на имя.
#
# Браузер всё равно один раз покажет предупреждение «сертификат не доверенный» —
# это нормально для самоподписанного. Нужно нажать «Дополнительно» →
# «Перейти на сайт». После этого микрофон работать будет.
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CERT_DIR="$APP_DIR/docker/nginx/certs"
mkdir -p "$CERT_DIR"

command -v openssl >/dev/null || { echo "Не найден openssl. Установите: apt install openssl"; exit 1; }

PRIMARY="${1:-localhost}"
shift || true

ALT="DNS:localhost,IP:127.0.0.1"
if [[ "$PRIMARY" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    ALT="$ALT,IP:$PRIMARY"
else
    ALT="$ALT,DNS:$PRIMARY"
fi

for name in "$@"; do
    if [[ "$name" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        ALT="$ALT,IP:$name"
    else
        ALT="$ALT,DNS:$name"
    fi
done

# Дубли в списке имён браузеру не мешают, но выглядят как ошибка — убираем.
ALT="$(echo "$ALT" | tr ',' '\n' | awk '!seen[$0]++' | paste -sd, -)"

openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
    -keyout "$CERT_DIR/server.key" \
    -out "$CERT_DIR/server.crt" \
    -subj "/C=RU/O=AI Assistant/CN=$PRIMARY" \
    -addext "subjectAltName=$ALT" 2>/dev/null

chmod 644 "$CERT_DIR/server.crt"
chmod 600 "$CERT_DIR/server.key"

echo "Сертификат создан для: $PRIMARY ($ALT)"
echo "Действует 10 лет, лежит в docker/nginx/certs/"
