#!/bin/bash
set -e

# Ensure only prefork MPM is loaded
a2dismod mpm_event mpm_worker 2>/dev/null || true
a2enmod mpm_prefork 2>/dev/null || true

# Start Apache
exec apache2-foreground

