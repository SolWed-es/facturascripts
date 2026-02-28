<?php
/**
 * Docker development config for FacturaScripts.
 * Copy this file to config.php to skip the web installer.
 *
 * Usage:
 *   cp .docker/config.php config.php
 */

define('FS_DB_TYPE', 'postgresql');
define('FS_DB_HOST', 'db');
define('FS_DB_PORT', 5432);
define('FS_DB_NAME', 'facturascripts');
define('FS_DB_USER', 'postgres');
define('FS_DB_PASS', 'postgres');
define('FS_LANG', 'es_ES');
define('FS_TIMEZONE', 'Europe/Madrid');
define('FS_ROUTE', '');
define('FS_DEBUG', true);
