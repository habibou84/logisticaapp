# syntax=docker/dockerfile:1
#
# Deux images construites par docker-compose.yml :
#  - « app » : PHP-FPM (site), Horizon (files d'attente), Reverb (temps réel) et tâches planifiées ;
#  - « web » : Nginx avec les fichiers publics (interface compilée à la construction).

# ── 1. Interface (Vite) ──────────────────────────────────────────────────────────────────────────
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
# Temps réel vu du navigateur : adresse publique du site (HTTPS, port 443) et clé publique de Reverb
# (visible dans le navigateur : ce n'est pas le secret REVERB_APP_SECRET)
ARG VITE_APP_NAME=Livraison
ARG VITE_REVERB_APP_KEY=
ARG VITE_REVERB_HOST=
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https
ENV VITE_APP_NAME=$VITE_APP_NAME VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME
RUN npm run build

# ── 2. PHP ───────────────────────────────────────────────────────────────────────────────────────
FROM php:8.3-fpm-bookworm AS app

# Extensions : PostgreSQL, Redis, images (logos, icônes), Excel (zip), Horizon (pcntl)
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libzip-dev libicu-dev libpng-dev \
        libjpeg62-turbo-dev libfreetype6-dev libwebp-dev postgresql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl gd zip bcmath pcntl exif sockets opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www/html

# Dépendances PHP d'abord (couche gardée en cache tant que composer.lock ne change pas)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist

COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

# ── 3. Serveur web (Nginx) : fichiers publics de la même version ─────────────────────────────────
FROM nginx:1.27-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
