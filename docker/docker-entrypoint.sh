#!/bin/bash
set -e

# Ensure only prefork MPM is loaded
a2dismod mpm_event mpm_worker 2>/dev/null || true
a2enmod mpm_prefork 2>/dev/null || true

# Configure PHP session settings from environment variables
SESSION_CONFIG_FILE="/usr/local/etc/php/conf.d/amember-session.ini"

# Set cookie domain if provided, otherwise leave empty
if [ -n "$SESSION_COOKIE_DOMAIN" ]; then
    echo "session.cookie_domain = $SESSION_COOKIE_DOMAIN" >> "$SESSION_CONFIG_FILE"
fi

# Set cookie secure flag (default to 0 for local, 1 for production)
SESSION_COOKIE_SECURE="${SESSION_COOKIE_SECURE:-0}"
echo "session.cookie_secure = $SESSION_COOKIE_SECURE" >> "$SESSION_CONFIG_FILE"

# Start Apache
exec apache2-foreground

