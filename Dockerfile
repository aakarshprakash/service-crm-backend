# syntax=docker/dockerfile:1.7
# Production image: nginx + PHP-FPM serving the Laravel API only.
# The frontend is deployed separately (e.g. Cloudflare Pages) — see VITE_API_URL in frontend/.env.example.

# ---- 1. PHP dependencies -----------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader --ignore-platform-reqs

# ---- 2. Runtime --------------------------------------------------------------
FROM php:8.3-fpm-alpine AS app
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql redis gd zip intl bcmath opcache pcntl \
 && apk add --no-cache nginx supervisor tzdata \
 && rm -rf /var/cache/apk/*

WORKDIR /var/www/app
COPY . ./
COPY --from=vendor /app/vendor ./vendor
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN rm -f .env \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && php artisan package:discover --ansi

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD wget -qO- http://127.0.0.1:8080/up >/dev/null || exit 1
ENTRYPOINT ["entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
