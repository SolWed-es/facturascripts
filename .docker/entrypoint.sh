#!/bin/bash
set -e

# Install Composer dependencies if vendor is missing (dev mode with mounted volume)
if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "[entrypoint] Running composer install..."
    composer install --no-interaction --prefer-dist --optimize-autoloader --working-dir=/var/www/html
fi

# Copy Docker config template if config.php doesn't exist
if [ ! -f /var/www/html/config.php ]; then
    echo "[entrypoint] Copying .docker/config.php → config.php"
    cp /var/www/html/.docker/config.php /var/www/html/config.php
fi

# Create .htaccess from sample if missing
if [ ! -f /var/www/html/.htaccess ]; then
    echo "[entrypoint] Creating .htaccess from htaccess-sample"
    cp /var/www/html/htaccess-sample /var/www/html/.htaccess
fi

# Ensure writable directories exist
mkdir -p /var/www/html/MyFiles/Tmp /var/www/html/MyFiles/uploads /var/www/html/Dinamic

# Always regenerate Dinamic/ on startup to avoid stale class errors
echo "[entrypoint] Regenerating Dinamic/..."
php -r "
    const FS_FOLDER = '/var/www/html';
    require_once '/var/www/html/vendor/autoload.php';
    require_once '/var/www/html/config.php';

    // Read enabled plugins from MyFiles/plugins.json (if exists)
    \$pluginsFile = FS_FOLDER . '/MyFiles/plugins.json';
    if (file_exists(\$pluginsFile)) {
        \$all = json_decode(file_get_contents(\$pluginsFile), true) ?: [];
        \$enabled = array_map(fn(\$p) => \$p['name'], array_filter(\$all, fn(\$p) => \$p['enabled'] ?? false));
    } else {
        \$enabled = [];
    }

    FacturaScripts\Core\Internal\PluginsDeploy::run(\$enabled, true);
    echo 'Dinamic deployed (' . count(\$enabled) . ' plugins)' . PHP_EOL;
" || echo "[entrypoint] WARNING: Dinamic deploy failed, continuing anyway"

# Fix permissions for www-data
echo "[entrypoint] Fixing permissions..."
chown -R www-data:www-data \
    /var/www/html/MyFiles \
    /var/www/html/Dinamic

# Only chown config.php if it exists and is writable
[ -f /var/www/html/config.php ] && chown www-data:www-data /var/www/html/config.php 2>/dev/null || true

exec apache2-foreground
