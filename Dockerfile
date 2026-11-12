FROM php:8.4-cli-alpine

# Install system dependencies & PostgreSQL dev libraries
RUN apk add --no-cache \
    curl \
    git \
    unzip \
    libpq-dev \
    linux-headers \
    $PHPIZE_DEPS

# Install PHP extensions: pdo_pgsql, pgsql, bcmath, pcntl, and redis
RUN docker-php-ext-install pdo_pgsql pgsql bcmath pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
