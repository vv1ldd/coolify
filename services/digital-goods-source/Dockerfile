# Base digests: see marketplace/docker/base-images.lock.json (shared lock)
FROM composer:2@sha256:7725eb4545c438629ae8bde3ef0bb9a5038ef566126ad878442a69007242d267 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts
COPY . .
RUN composer dump-autoload --optimize

FROM php:8.4-cli-alpine@sha256:708490001d69a35b977e0ad19557b3528556a1497189f2969142ef302ad2c20d
WORKDIR /app

RUN apk add --no-cache bash curl libpq-dev sqlite \
    && docker-php-ext-install pdo pdo_pgsql

COPY --from=vendor /app /app

RUN mkdir -p /data /app/storage/framework/cache/data /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs \
    && chmod +x /app/artisan

EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=3s --retries=10 CMD curl -fsS http://127.0.0.1:8080/health || exit 1

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
