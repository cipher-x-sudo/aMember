#!/bin/bash

# Get PORT from environment variable (Railway provides this)
PORT=${PORT:-8080}

# Replace PORT in nginx.conf
sed -i "s/listen 8080;/listen ${PORT};/g" /app/nginx.conf

# Start PHP-FPM in background
php-fpm -D

# Start Nginx in foreground
nginx -g 'daemon off;' -c /app/nginx.conf

