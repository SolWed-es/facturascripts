# FacturaScripts - SolWed

## Estructura de repositorios

Todo el ecosistema FacturaScripts de SolWed está centralizado en **SolWed-es/facturascripts**.

### Ramas principales

| Rama | Propósito |
|------|-----------|
| `master` | **INTOCABLE** — sync con upstream NeoRazorX/facturascripts. Solo se actualiza haciendo merge del upstream. |
| `solwed/production` | Rama de producción. Core modificado + assets erpsolwed + plugin-list.json. **Rama por defecto en GitHub.** |

### Ramas de plugins propios (4)

Cada plugin parte de `master` y añade únicamente su carpeta `Plugins/NombrePlugin/`.
El `.gitignore` de cada rama excluye todos los plugins excepto el propio.

| Rama | Plugin | Descripción |
|------|--------|-------------|
| `plugin/SolwedTheme` | `Plugins/SolwedTheme/` | Tema visual personalizado de SolWed (CSS, JS, Twig templates) |
| `plugin/SolwedPlugins` | `Plugins/SolwedPlugins/` | Gestor de plugins con tienda Portal Solwed y descarga desde GitHub |
| `plugin/Blog` | `Plugins/Blog/` | Plugin de blog con modelos, API y soporte AI |
| `plugin/ImportarFacturasEmail` | `Plugins/ImportarFacturasEmail/` | Importación automática de facturas por email (IMAP + cron) |

### Ramas de plugins importados (31)

31 plugins del fork demo.erpsolwed.es importados como ramas `plugin/*`.
Todos tienen release publicado en GitHub. Ver `plugin-list.json` para la lista completa.

## Modificaciones al Core (solwed/production)

El Core de FacturaScripts tiene las siguientes modificaciones propias en `solwed/production`:

### Sistema de actualizaciones (reemplaza Forja/Telemetría)

| Archivo | Cambio |
|---------|--------|
| `Core/Internal/SolwedGitHub.php` | **NUEVO** — cliente GitHub API para updates del core y plugins propios |
| `Core/Controller/Updater.php` | Reemplaza Forja/Telemetría por `SolwedGitHub` |
| `Core/View/Updater.html.twig` | Sin telemetría ni registro |

### Instalación directa desde GitHub Releases (en "Más plugins")

| Archivo | Cambio |
|---------|--------|
| `Core/Internal/SolwedGitHubPlugins.php` | **NUEVO** — fetcha `plugin-list.json` de GitHub, devuelve mapa de plugins disponibles |
| `Core/Controller/AdminPlugins.php` | Añade acción `github-install` + `markGithubPlugins()` (marca `in_github` en Forja list) |
| `Core/View/AdminPlugins.html.twig` | `showAllPlugins` muestra botón "Instalar" directo cuando `plugin.in_github` |

Cuando SolwedPlugins está activo, su `Controller/AdminPlugins.php` extiende el Core y añade el tab "Portal Solwed" con los 35 plugins de GitHub.

### Reglas Dinamic/

- **NO** copiar `Core/*.php` a `Dinamic/` con el mismo namespace → clase duplicada
- `Dinamic/Controller/Updater.php` debe ser proxy: `class Updater extends \FacturaScripts\Core\Controller\Updater {}`
- `Core/Internal/*` **nunca** se proxifica en `Dinamic/Internal/`

### Traducciones

Siempre que se use `trans('clave')` en una view o `Tools::log()->error/warning('clave')` en un controller, añadir la clave a:
- `Core/Translation/es_ES.json`
- `Core/Translation/en_EN.json`

Las claves van en **orden alfabético** dentro del JSON.

## Sistema de releases (GitHub Actions)

Todo el sistema de publicación usa un único repo: **SolWed-es/facturascripts**.

### Formato de tags

| Tipo | Tag | Asset |
|------|-----|-------|
| Core | `v2025.93` | `facturascripts.zip` |
| Plugin | `SolwedTheme-v1.73` | `SolwedTheme.zip` |

### Workflows

| Archivo | Trigger | Qué hace |
|---------|---------|----------|
| `.github/workflows/release.yml` | push `v*` | Build ZIP del core desde `solwed/production` |
| `.github/workflows/release-plugin.yml` | push `*-v*` o `workflow_dispatch` | Build ZIP del plugin + actualiza `plugin-list.json` en `solwed/production` |

### facturascripts.ini de cada plugin

```ini
github = 'SolWed-es/facturascripts:SolwedTheme'
```

Formato: `repo:TagPrefix`. `SolwedGitHub` filtra releases por tags que empiecen por `TagPrefix-v`.

### Catálogo (plugin-list.json)

Ubicado en la raíz de `solwed/production`. Contiene los 35 plugins con `source: github`.
Se actualiza automáticamente al publicar una release de plugin.

La tienda (`SolwedGitHubPlugins.php`) lo fetcha desde:
```
https://raw.githubusercontent.com/SolWed-es/facturascripts/solwed/production/plugin-list.json
```

### Publicar release

```bash
# Core
git tag v2025.93 && git push origin v2025.93

# Plugin (plugin-list.json se actualiza automáticamente vía Actions)
git tag SolwedTheme-v1.73 && git push origin SolwedTheme-v1.73
```

## Scripts de mantenimiento

| Script | Propósito |
|--------|-----------|
| `scripts/import-demo-plugins.py` | Importa plugins de demo.erpsolwed.es → crea ramas `plugin/*` |
| `scripts/tag-plugins.sh` | Crea tags para todos los `plugin/*` |

**Antes de usar los scripts**: `sudo chown -R ivan:ivan Core/ Plugins/`

## Assets de diseño (solwed/production)

Los assets del proyecto `erpsolwed` (archivado) están en `MyFiles/erpsolwed/`:

```
MyFiles/erpsolwed/
├── logos/   (logo-claro.png, logo-oscuro.png, servicio.png, favicon.svg)
└── images/  (mockup-erp.png, og-image.png, ...)
    └── features/  (contabilidad, facturacion, informes, inventario, tpv, verifactu, analisis, logistica)
```

## Otros repositorios relacionados (SolWed-es)

| Repo | Visibilidad | Descripción |
|------|-------------|-------------|
| `SolwedTheme` | Público | Espejo/versión standalone del tema CSS |
| `SyncWoocommerceProducts` | Público | Plugin sync productos con WooCommerce |
| `IeWhatsapp` | Público | Plugin WhatsApp para FacturaScripts |

## Flujo de trabajo

1. **Actualizar core**: merge desde `upstream/master` → `master`
2. **Desarrollar plugin**: trabajar en la rama `plugin/NombrePlugin`
3. **Producción**: mergear plugins necesarios en `solwed/production`
4. **Publicar**: `git tag NombrePlugin-vX.Y && git push origin NombrePlugin-vX.Y`

## Docker (desarrollo local)

### Arranque

```bash
docker compose up -d
```

- App: http://localhost:8080
- PostgreSQL: `localhost:5432`

### Credenciales PostgreSQL por defecto

| Campo | Valor |
|-------|-------|
| Host | `db` (dentro de Docker) / `localhost` (desde host) |
| Puerto | `5432` |
| Base de datos | `facturascripts` |
| Usuario | `postgres` |
| Contraseña | `postgres` |

### Instalación inicial

**Opción A — Web installer** (primera vez sin config.php):
1. `docker compose up -d`
2. Acceder a http://localhost:8080 y seguir el instalador web

**Opción B — Config automática**:
```bash
cp .docker/config.php config.php
docker compose up -d
```

### Archivos Docker

| Archivo | Propósito |
|---------|-----------|
| `Dockerfile` | php:8.2-apache + extensiones pgsql/gd/bcmath/zip |
| `docker-compose.yml` | Servicios app + db (postgres:alpine) |
| `.docker/apache.conf` | VirtualHost con AllowOverride All |
| `.docker/php.ini` | 99M upload, 256M memory, 10000 input_vars |
| `.docker/config.php` | Plantilla de config pre-configurada para Docker |
| `.docker/entrypoint.sh` | Auto-composer + copia de config + chown www-data al arrancar |

### Permisos (problema frecuente)

Los archivos de `Core/`, `Plugins/`, `MyFiles/` pueden quedar en propiedad de `www-data` (Docker) o `ivan` (host):

```bash
# Cuando git falla ("Permission denied") → arreglar en host:
sudo chown -R ivan:ivan Core/ Plugins/

# Cuando la app PHP falla ("Permission denied") → arreglar en contenedor:
sudo docker exec facturascripts-app-1 bash -c \
  "chown -R www-data:www-data /var/www/html/MyFiles /var/www/html/Core /var/www/html/Plugins; \
   rm -rf /var/www/html/MyFiles/Cache/Twig/*"

# docker compose restart también restaura permisos (el entrypoint hace el chown)
```

## Git config para commits SolWed

```bash
git config user.name "Iván Moreno Quiros"
git config user.email "dev@solwed.es"
```
