#!/bin/bash
set -e

# Ensure only prefork MPM is loaded
a2dismod mpm_event mpm_worker 2>/dev/null || true
a2enmod mpm_prefork 2>/dev/null || true

# Debug: Log all SESSION_* environment variables (for troubleshooting on Railway)
echo "[Entrypoint] === Environment Variables Debug ===" >&2
env | grep -i session || echo "[Entrypoint] No SESSION_* variables found" >&2
echo "[Entrypoint] SESSION_COOKIE_DOMAIN=${SESSION_COOKIE_DOMAIN:-NOT SET}" >&2
echo "[Entrypoint] SESSION_COOKIE_SECURE=${SESSION_COOKIE_SECURE:-NOT SET}" >&2

# Strip quotes from environment variables if present (Railway sometimes includes them)
if [ -n "$SESSION_COOKIE_DOMAIN" ] && [ "$SESSION_COOKIE_DOMAIN" != "NOT SET" ]; then
    SESSION_COOKIE_DOMAIN=$(echo "$SESSION_COOKIE_DOMAIN" | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//")
    echo "[Entrypoint] SESSION_COOKIE_DOMAIN (after strip)=${SESSION_COOKIE_DOMAIN}" >&2
fi

if [ -n "$SESSION_COOKIE_SECURE" ] && [ "$SESSION_COOKIE_SECURE" != "NOT SET" ]; then
    SESSION_COOKIE_SECURE=$(echo "$SESSION_COOKIE_SECURE" | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//")
    echo "[Entrypoint] SESSION_COOKIE_SECURE (after strip)=${SESSION_COOKIE_SECURE}" >&2
fi
echo "[Entrypoint] === End Debug ===" >&2

# Configure PHP session settings from environment variables
# Update .user.ini file if it exists
USER_INI_FILE="/var/www/html/.user.ini"
if [ -f "$USER_INI_FILE" ]; then
    echo "[Entrypoint] Found .user.ini, updating session settings..." >&2
    # Remove existing cookie_domain and cookie_secure lines if present
    sed -i '/^session\.cookie_domain/d' "$USER_INI_FILE"
    sed -i '/^session\.cookie_secure/d' "$USER_INI_FILE"
    
    # Add cookie_domain if environment variable is set
    if [ -n "$SESSION_COOKIE_DOMAIN" ]; then
        echo "[Entrypoint] Setting session.cookie_domain = $SESSION_COOKIE_DOMAIN" >&2
        # Add after session.cookie_path line
        sed -i "/^session\.cookie_path/a session.cookie_domain = $SESSION_COOKIE_DOMAIN" "$USER_INI_FILE"
    else
        echo "[Entrypoint] SESSION_COOKIE_DOMAIN not set, leaving cookie_domain empty" >&2
    fi
    
    # Add cookie_secure setting
    SESSION_COOKIE_SECURE="${SESSION_COOKIE_SECURE:-0}"
    echo "[Entrypoint] Setting session.cookie_secure = $SESSION_COOKIE_SECURE" >&2
    sed -i "/^session\.cookie_path/a session.cookie_secure = $SESSION_COOKIE_SECURE" "$USER_INI_FILE"
else
    echo "[Entrypoint] .user.ini not found at $USER_INI_FILE" >&2
fi

# Also update the PHP conf.d file for system-wide settings
SESSION_CONFIG_FILE="/usr/local/etc/php/conf.d/amember-session.ini"
# Clear any existing config
> "$SESSION_CONFIG_FILE"

# Set cookie domain if provided, otherwise leave empty
if [ -n "$SESSION_COOKIE_DOMAIN" ]; then
    echo "session.cookie_domain = $SESSION_COOKIE_DOMAIN" >> "$SESSION_CONFIG_FILE"
    echo "[Entrypoint] Added cookie_domain to $SESSION_CONFIG_FILE" >&2
fi

# Set cookie secure flag (default to 0 for local, 1 for production)
SESSION_COOKIE_SECURE="${SESSION_COOKIE_SECURE:-0}"
echo "session.cookie_secure = $SESSION_COOKIE_SECURE" >> "$SESSION_CONFIG_FILE"
echo "[Entrypoint] Added cookie_secure to $SESSION_CONFIG_FILE" >&2

# Start Apache
exec apache2-foreground

