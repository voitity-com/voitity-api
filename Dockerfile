FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpq-dev \
    nginx \
    supervisor \
    unzip \
    libzip-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    zip \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd pcntl pdo pdo_pgsql zip

# Install Composer
COPY --from=composer:2.6 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy existing application directory contents
COPY src/ /var/www/html

# Install Laravel dependencies
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Add startup checks for bind-mounted development source
COPY docker/entrypoint.sh /usr/local/bin/laravel-entrypoint
COPY docker/supervisord-queue.conf /etc/supervisor/conf.d/laravel-queue.conf
COPY docker/supervisord-api.conf /etc/supervisor/supervisord-api.conf
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
RUN chmod +x /usr/local/bin/laravel-entrypoint \
    && rm -f /etc/nginx/sites-enabled/default

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Expose the internal HTTP port served by Nginx.
EXPOSE 8000
ENTRYPOINT ["laravel-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord-api.conf"]
