FROM php:8.1-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    nginx \
    wget \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libxml2-dev \
    libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
# Install dom first (required by xmlreader)
RUN docker-php-ext-install dom

# Install xmlreader after dom (depends on dom headers)
RUN docker-php-ext-install xmlreader

# Install remaining extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_mysql \
    gd \
    mbstring \
    iconv \
    xml \
    xmlwriter \
    ctype \
    curl \
    zip

# Install IonCube Loader
RUN cd /tmp \
    && wget -q https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz \
    && tar -xzf ioncube_loaders_lin_x86-64.tar.gz \
    && PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;") \
    && PHP_ZTS=$(php -r "echo ZEND_THREAD_SAFE ? 'ts' : 'nts';") \
    && PHP_EXT_DIR=$(php-config --extension-dir) \
    && if [ -f "ioncube/ioncube_loader_lin_${PHP_VERSION}_${PHP_ZTS}.so" ]; then \
        cp ioncube/ioncube_loader_lin_${PHP_VERSION}_${PHP_ZTS}.so ${PHP_EXT_DIR}/ioncube_loader.so; \
    elif [ -f "ioncube/ioncube_loader_lin_${PHP_VERSION}.so" ]; then \
        cp ioncube/ioncube_loader_lin_${PHP_VERSION}.so ${PHP_EXT_DIR}/ioncube_loader.so; \
    else \
        echo "Warning: IonCube loader not found for PHP ${PHP_VERSION}, trying PHP 8.1"; \
        cp ioncube/ioncube_loader_lin_8.1_nts.so ${PHP_EXT_DIR}/ioncube_loader.so 2>/dev/null || \
        cp ioncube/ioncube_loader_lin_8.1.so ${PHP_EXT_DIR}/ioncube_loader.so 2>/dev/null || \
        echo "Error: Could not find IonCube loader"; \
    fi \
    && echo "zend_extension=${PHP_EXT_DIR}/ioncube_loader.so" > /usr/local/etc/php/conf.d/00-ioncube.ini \
    && rm -rf /tmp/ioncube* \
    && php -m 2>&1 | grep -i ioncube || echo "IonCube extension file installed (will be loaded at runtime)"

# Configure PHP
RUN echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "upload_max_filesize = 20M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "post_max_size = 20M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "date.timezone = UTC" >> /usr/local/etc/php/conf.d/custom.ini

# Copy application files
WORKDIR /var/www/html
COPY . /var/www/html/

# Set permissions for writable directories
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/data \
    && chmod -R 777 /var/www/html/data/cache \
    && chmod -R 777 /var/www/html/data/new-rewrite \
    && chmod -R 777 /var/www/html/data/public

# Copy Nginx configuration
COPY nginx.conf /etc/nginx/sites-available/default

# Create startup script
RUN echo '#!/bin/bash\n\
set -e\n\
\n\
# Start PHP-FPM in background\n\
php-fpm -D\n\
\n\
# Replace PORT in nginx config if provided\n\
if [ ! -z "$PORT" ]; then\n\
    sed -i "s/listen 8080;/listen $PORT;/g" /etc/nginx/sites-available/default\n\
fi\n\
\n\
# Start Nginx in foreground\n\
nginx -g "daemon off;"' > /start.sh \
    && chmod +x /start.sh

# Expose port
EXPOSE 8080

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:8080/ || exit 1

# Start services
CMD ["/start.sh"]

