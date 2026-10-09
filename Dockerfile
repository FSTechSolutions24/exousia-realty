FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction

FROM node:24-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.mts tsconfig.json postcss.config.js tailwind.config.js ./
RUN npm run build

FROM php:8.2-fpm-alpine
RUN apk add --no-cache icu-dev oniguruma-dev libzip-dev \
    && docker-php-ext-install pdo_mysql bcmath intl mbstring zip opcache
WORKDIR /var/www/html
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
RUN chown -R www-data:www-data storage bootstrap/cache
USER www-data
CMD ["php-fpm"]
