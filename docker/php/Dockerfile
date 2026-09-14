FROM php:8.4-fpm

ARG WWWUSER=1000
ARG WWWGROUP=1000

# Системные пакеты.
# smbclient — чтобы ходить в сетевые папки Windows без прав root на монтирование.
# unixodbc — зависимость драйвера Microsoft SQL Server, на котором обычно стоит 1С.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git curl unzip zip gosu \
        libpq-dev libzip-dev libicu-dev libonig-dev libxml2-dev \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
        smbclient \
        gnupg2 apt-transport-https ca-certificates \
        unixodbc unixodbc-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql mbstring bcmath gd zip intl opcache pcntl exif

# Драйвер Microsoft SQL Server. Нужен, только если база 1С живёт на MS SQL.
# Если 1С на PostgreSQL, работают через OData или базу 1С вообще не подключают —
# драйвер не нужен.
#
# Сборка не падает, если поставить его не вышло: в закрытых сетях pecl.php.net
# часто недоступен, и из-за необязательного драйвера вставало всё приложение.
# Отключить заранее: docker compose build --build-arg WITH_MSSQL=false
ARG WITH_MSSQL=true

RUN if [ "$WITH_MSSQL" = "false" ]; then \
        echo "MS SQL: пропущен по просьбе (WITH_MSSQL=false)"; \
    else \
        ( curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft.gpg \
          && echo "deb [signed-by=/usr/share/keyrings/microsoft.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" > /etc/apt/sources.list.d/mssql-release.list \
          && apt-get update \
          && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
          && pecl install sqlsrv pdo_sqlsrv \
          && docker-php-ext-enable sqlsrv pdo_sqlsrv \
          && echo "MS SQL: драйвер установлен" ) \
        || echo "MS SQL: ВНИМАНИЕ, драйвер не установлен. Приложение будет работать, но подключить базу 1С на MS SQL не получится. Проверьте доступ к pecl.php.net."; \
    fi; \
    rm -rf /var/lib/apt/lists/*

# Расширение redis ускоряет работу с кэшем, но не обязательно: без него
# приложение использует predis — ту же работу на чистом PHP.
RUN ( pecl install redis && docker-php-ext-enable redis && echo "redis: расширение установлено" ) \
    || echo "redis: расширение не установлено, будет использован predis"

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN groupadd --force -g ${WWWGROUP} app \
    && useradd -ms /bin/bash --no-user-group -g ${WWWGROUP} -u ${WWWUSER} app

WORKDIR /var/www

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
EXPOSE 9000
CMD ["php-fpm"]
