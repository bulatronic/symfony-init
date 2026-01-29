FROM dunglas/frankenphp:1-php8.5

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY . .

RUN mkdir -p /app/public /app/var/cache /app/var/log \
    && chown -R www-data:www-data /app/var \
    && chmod -R 775 /app/var \
    && chown www-data:www-data /app
