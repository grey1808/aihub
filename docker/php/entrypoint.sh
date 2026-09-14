#!/bin/bash
set -e

cd /var/www

# В боевом режиме opcache держит скомпилированный код в памяти и не проверяет
# файлы — так быстрее. Но при разработке из-за этого правки в коде просто
# не видны в браузере, пока не перезапустишь контейнер. Поэтому вне production
# включаем проверку изменений.
if [ "${APP_ENV:-production}" != "production" ]; then
    printf 'opcache.validate_timestamps = 1\nopcache.revalidate_freq = 0\n' \
        > /usr/local/etc/php/conf.d/99-dev.ini
else
    rm -f /usr/local/etc/php/conf.d/99-dev.ini
fi

# Права на записываемые каталоги.
#
# php-fpm обслуживает запросы от имени пользователя app, а файлы в storage
# могли остаться от root (например, лог создал контейнер, запущенный иначе).
# Тогда приложение молча падает с «Permission denied» на записи лога —
# поэтому владельца выставляем принудительно на каждом старте.
mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/app/attachments bootstrap/cache

if [ "$(id -u)" = "0" ]; then
    chown -R app:app storage bootstrap/cache 2>/dev/null || true
fi

chmod -R ug+rw storage bootstrap/cache 2>/dev/null || true

if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
    # Ждём базу: postgres поднимается дольше php-fpm, и первая миграция
    # без этого падает при каждом включении компьютера.
    until php -r "new PDO('pgsql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));" 2>/dev/null; do
        echo "Ждём базу данных..."
        sleep 2
    done

    php artisan migrate --force --no-interaction
    php artisan db:seed --force --no-interaction

    if [ "${APP_ENV:-production}" = "production" ]; then
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
    else
        php artisan optimize:clear
    fi

    # --force и полное молчание: без этого при каждом запуске в логах
    # появляется красное «ERROR: link already exists», хотя это не ошибка,
    # а ровно то, чего мы и ждём на втором запуске.
    php artisan storage:link --force >/dev/null 2>&1 || true

    # Прогреваем модели в фоне: первый запрос поднимает модель с диска
    # в память, и для большой модели это десятки секунд. Пусть это время
    # тратит запуск, а не первый сотрудник утром.
    # В фоне и с игнорированием ошибок — приложение должно подняться
    # даже если нейросеть ещё не готова.
    ( php artisan aihub:warmup --quiet-fail >/dev/null 2>&1 & ) || true
fi

exec "$@"
