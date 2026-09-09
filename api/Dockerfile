FROM webdevops/php-nginx:8.3-alpine

ENV WEB_DOCUMENT_ROOT=/app/public
WORKDIR /app

RUN apk add --no-cache ffmpeg

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction \
 && mkdir -p /app/storage/app/meetings/original /app/storage/app/meetings/processed /app/storage/logs \
 && chown -R application:application /app/storage /app/bootstrap/cache

COPY docker/supervisor/laravel-worker.conf /opt/docker/etc/supervisor.d/laravel-worker.conf
RUN mkdir -p /etc/supervisor/conf.d && cp /opt/docker/etc/supervisor.d/laravel-worker.conf /etc/supervisor/conf.d/laravel-worker.conf
