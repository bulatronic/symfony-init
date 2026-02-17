FROM dunglas/frankenphp:1-php8.5

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# install-php-extensions is already in the base image
# amqp needed so Composer can resolve/install projects with RabbitMQ (symfony/amqp-messenger)
RUN set -eux; install-php-extensions zip opcache amqp

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY . .

RUN mkdir -p /app/public /app/var/cache /app/var/log \
    && chown -R www-data:www-data /app/var \
    && chmod -R 775 /app/var \
    && chown www-data:www-data /app
