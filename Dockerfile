FROM php:8.1-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    curl \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
# Note: php:8.1-apache already includes: xml, dom, xmlreader, xmlwriter, ctype, iconv, mbstring, pdo
# We only need to install: pdo_mysql, gd, zip

# Configure and install GD extension
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    gd \
    zip

# Install ionCube Loader for PHP 8.1
RUN curl -fSL 'https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz' -o ioncube.tar.gz \
    && tar -xzf ioncube.tar.gz -C /usr/local \
    && rm ioncube.tar.gz \
    && echo "zend_extension=/usr/local/ioncube/ioncube_loader_lin_8.1.so" > /usr/local/etc/php/conf.d/00-ioncube.ini

# Enable Apache mod_rewrite (MPM prefork is already configured in base image)
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy application files   
COPY . /var/www/html/

# Create writable directories and set permissions
RUN mkdir -p data/cache data/new-rewrite data/public \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 data/ data/cache data/new-rewrite data/public

# Create session directory for PHP sessions
RUN mkdir -p /tmp/php_sessions \
    && chown -R www-data:www-data /tmp/php_sessions \
    && chmod 777 /tmp/php_sessions

# Configure Apache
RUN sed -i 's!/var/www/html!/var/www/html!g' /etc/apache2/sites-available/000-default.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Configure PHP to suppress deprecation warnings (they're already handled with ReturnTypeWillChange attributes)
# E_ALL = 32767, E_DEPRECATED = 8192, E_STRICT = 2048
# So: 32767 & ~8192 & ~2048 = 22527
RUN echo "error_reporting = 22527" >> /usr/local/etc/php/conf.d/amember.ini \
    && echo "display_errors = On" >> /usr/local/etc/php/conf.d/amember.ini \
    && echo "opcache.enable=0" >> /usr/local/etc/php/conf.d/amember.ini \
    && echo "log_errors = On" >> /usr/local/etc/php/conf.d/amember.ini \
    && echo "error_log = /proc/self/fd/2" >> /usr/local/etc/php/conf.d/amember.ini

# Copy and set entrypoint
COPY docker/docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Expose port 80
EXPOSE 80

CMD ["/usr/local/bin/docker-entrypoint.sh"]

