#!/bin/bash
set -e

# Install Composer dependencies if vendor is missing
if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "[entrypoint] Running composer install..."
    composer install --no-interaction --prefer-dist --optimize-autoloader --working-dir=/var/www/html
fi

# Copy Docker config template if config.php doesn't exist
if [ ! -f /var/www/html/config.php ]; then
    echo "[entrypoint] Copying .docker/config.php → config.php"
    cp /var/www/html/.docker/config.php /var/www/html/config.php
fi

# Create .htaccess from sample if missing (needed when skipping the web installer)
if [ ! -f /var/www/html/.htaccess ]; then
    echo "[entrypoint] Creating .htaccess from htaccess-sample"
    cp /var/www/html/htaccess-sample /var/www/html/.htaccess
fi

# Ensure writable directories exist
mkdir -p /var/www/html/MyFiles/Tmp /var/www/html/MyFiles/uploads /var/www/html/Dinamic

# Deploy Dinamic/ if empty (volume mount creates empty dir before our code runs)
if [ -z "$(ls -A /var/www/html/Dinamic 2>/dev/null)" ]; then
    echo "[entrypoint] Deploying Dinamic/ via Plugins::deploy()..."
    php -r "
        const FS_FOLDER = '/var/www/html';
        require_once '/var/www/html/vendor/autoload.php';
        require_once '/var/www/html/config.php';
        use FacturaScripts\Core\Plugins;
        Plugins::deploy();
        echo 'Deploy done.' . PHP_EOL;
    "
fi

# Fix permissions for www-data on all writable directories.
# - MyFiles/: uploads, cache, logs, crash reports
# - Dinamic/: regenerated on deploy (may contain root-owned files from CLI deploys)
# - Plugins/: installed/removed by the app at runtime
# - config.php: written by the installer
# Note: Core/ is intentionally excluded — read-only for Apache (o+r already set),
#       and keeping host ownership avoids permission issues in development.
echo "[entrypoint] Fixing permissions (MyFiles, Dinamic, Plugins, config.php)..."
chown -R www-data:www-data \
    /var/www/html/MyFiles \
    /var/www/html/Dinamic \
    /var/www/html/Plugins \
    /var/www/html/config.php

exec apache2-foreground
