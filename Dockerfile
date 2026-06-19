FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpq-dev \
    supervisor \
    unzip \
    libzip-dev \
    zip \
    && docker-php-ext-install pcntl pdo pdo_pgsql zip

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
COPY docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
RUN chmod +x /usr/local/bin/laravel-entrypoint

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Expose port 8000 and start Laravel
EXPOSE 8000
ENTRYPOINT ["laravel-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
