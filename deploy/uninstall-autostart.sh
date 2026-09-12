#!/usr/bin/env bash
# Отключить автозапуск при включении компьютера (само приложение не трогаем).
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "Нужны права root: sudo $0"; exit 1; }

systemctl disable --now aihub.service 2>/dev/null || true
rm -f /etc/systemd/system/aihub.service
systemctl daemon-reload

echo "Автозапуск отключён."
