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

exec apache2-foreground
