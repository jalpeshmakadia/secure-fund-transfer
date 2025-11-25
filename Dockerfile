FROM php:8.2-fpm-alpine

# Install system dependencies and PHP extensions
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    oniguruma-dev \
    mysql-client \
    linux-headers \
    rabbitmq-c \
    rabbitmq-c-dev \
    autoconf \
    g++ \
    make \
    && docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    opcache \
    sockets \
    && pecl install amqp \
    && docker-php-ext-enable amqp \
    && apk del autoconf g++ make rabbitmq-c-dev

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files
COPY composer.json composer.lock ./

# Install PHP dependencies (will be overridden by volume mount in dev)
RUN composer install --no-interaction --prefer-dist || true

# Copy application files
COPY . .

# Create var directory if it doesn't exist
RUN mkdir -p var/cache var/log

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/var

# Expose port 9000 for PHP-FPM
EXPOSE 9000

# Start PHP-FPM
CMD ["php-fpm"]

