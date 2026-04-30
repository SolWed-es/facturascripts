# facturascripts — AGENTS.md

Fork propio de [FacturaScripts](https://facturascripts.com) — ERP PHP open source. Rama `dev` activa con personalizaciones SOLWED.

## Stack

- **Lang**: PHP 8.2
- **Server**: Apache 2.4 (`mod_rewrite`)
- **DB**: PostgreSQL (DB `facturascripts`, user `fs_user`)
- **Extensions PHP**: bcmath, gd, mysqli, pdo_pgsql, pgsql, simplexml, zip, sodium, opcache
- **Deps**: composer (`--no-dev` en prod)

## Contenedor

- Name: `solwed-erp`
- Imagen: `solwed-stack-solwed-erp`
- Port: host 8081 → container 80
- Healthcheck: HTTP GET `/` (302 redirect OK)
- Dockerfile: `solwed-stack/dockerfiles/Dockerfile.erp` (multi-stage: composer + runtime)

## Config

- Config montado: `solwed-stack/configs/fs-config.php` → `/var/www/html/config.php`
- PHP ini: `solwed-stack/configs/php-production.ini`
- MyFiles (uploads/tmp): volumen `erp-myfiles`

Contenido `fs-config.php`:
```php
define('FS_DB_TYPE', 'postgresql');
define('FS_DB_HOST', 'solwed-postgres');
define('FS_DB_NAME', 'facturascripts');
define('FS_DB_USER', 'fs_user');
define('FS_DB_PASS', 'SolwedFSTest2026');
define('FS_LANG', 'es_ES');
define('FS_TIMEZONE', 'Europe/Madrid');
```

## Dev local

```bash
cd /opt/solwed/facturascripts
composer install
# Apache + PHP-FPM locales, o usar Docker
```

## Plugins

Plugins propios viven en `/opt/solwed/SolwedPlugins/`. Se copian a `Plugins/` de FS en build.

## Producción

URL: https://erp.solwed.es  
Stack prod: **Nginx + PHP-FPM nativos** (NO Docker — excepción). Compose: `/opt/docker-solwed/docker-compose.yml` en prod server.  
Deploy: `./deploy.sh deploy erp`  
Branch prod: `solwed/production`

## Gotchas

- Dockerfile: **NO purgar `libpq-dev`, `libpng-dev`, etc. post-install** — las extensions PHP las necesitan en runtime (libpq5, libpng16, libjpeg-turbo). Si se purgan → `libpq.so.5: cannot open shared object file`.
- Si composer install falla por `ext-pgsql missing` en stage composer:2 → añadir `--ignore-platform-req=ext-pgsql --ignore-platform-req=ext-bcmath --ignore-platform-req=ext-gd --ignore-platform-req=ext-mysqli`
- 74 archivos dirty — modificaciones propias SOLWED sobre upstream
- `.git`, `.docker/config.php`, `node_modules`, `package*.json` se eliminan en build (no producción)
- Branch `dev` local, `solwed/production` en server prod
