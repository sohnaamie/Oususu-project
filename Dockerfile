FROM php:8.4-cli

RUN apt-get update && apt-get install -y git unzip libpq-dev libzip-dev \
    && docker-php-ext-install pdo_pgsql pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction

CMD php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=${PORT:-8000}
