# Bono Loan Manager - production image (PHP 8.2 + Apache + PostgreSQL client tools for backups)
FROM php:8.2-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libonig-dev libcurl4-openssl-dev postgresql-client \
 && docker-php-ext-install pdo_pgsql pdo_mysql mbstring curl \
 && a2enmod rewrite headers remoteip \
 && rm -rf /var/lib/apt/lists/*

COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-bono.ini
COPY deploy/apache.conf /etc/apache2/conf-available/bono.conf
RUN a2enconf bono

WORKDIR /var/www/html
COPY . /var/www/html
# runtime data lives on mounted volumes, never inside the image
RUN mkdir -p /data/uploads /data/backups /data/logs \
 && chown -R www-data:www-data /data /var/www/html/storage \
 && rm -rf /var/www/html/tests /var/www/html/docs /var/www/html/deploy /var/www/html/.git /var/www/html/tools

ENV APP_ENV=production \
    DB_DRIVER=pgsql \
    STORAGE_PATH=/data/uploads \
    BACKUP_DIR=/data/backups \
    LOG_TO_STDERR=1 \
    SESSION_DRIVER=db \
    TRUST_PROXY=1

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s CMD curl -fsS "http://localhost/index.php?p=health" || exit 1
EXPOSE 80
