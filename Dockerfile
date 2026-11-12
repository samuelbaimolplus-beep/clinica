FROM node:22-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

FROM php:8.4-apache-bookworm AS php-base
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libonig-dev libicu-dev libzip-dev supervisor unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring intl zip bcmath pcntl opcache \
    && a2enmod rewrite headers expires \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

FROM php-base AS dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

FROM php-base AS production
COPY . .
COPY --from=dependencies /var/www/html/vendor ./vendor
COPY --from=assets /build/public/build ./public/build
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/production.ini
COPY deploy/supervisord.conf /etc/supervisor/conf.d/clinica.conf
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --optimize --no-interaction \
    && chmod +x deploy/start.sh \
    && chown -R www-data:www-data storage bootstrap/cache
ENV PORT=10000
ENV HOME=/var/www
EXPOSE 10000
CMD ["sh", "/var/www/html/deploy/start.sh"]
