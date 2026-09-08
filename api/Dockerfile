FROM webdevops/php-nginx:8.3-alpine

ENV WEB_DOCUMENT_ROOT=/app/public
WORKDIR /app

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction \
 && chown -R application:application /app/storage /app/bootstrap/cache
