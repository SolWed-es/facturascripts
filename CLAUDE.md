# FacturaScripts - SolWed

## Estructura de repositorios

Todo el ecosistema FacturaScripts de SolWed está centralizado en **SolWed-es/facturascripts**.

### Ramas principales

| Rama | Propósito |
|------|-----------|
| `master` | **INTOCABLE** — sync con upstream NeoRazorX/facturascripts. Solo se actualiza haciendo merge del upstream. |
| `solwed/production` | Rama de producción. Contiene assets de diseño de erpsolwed en `MyFiles/erpsolwed/`. |

### Ramas de plugins

Cada plugin parte de `master` y añade únicamente su carpeta `Plugins/NombrePlugin/`.
El `.gitignore` de cada rama excluye todos los plugins excepto el propio.

| Rama | Plugin | Descripción |
|------|--------|-------------|
| `plugin/SolwedTheme` | `Plugins/SolwedTheme/` | Tema visual personalizado de SolWed (CSS, JS, Twig templates) |
| `plugin/SolwedPlugins` | `Plugins/SolwedPlugins/` | Gestor de plugins con instalador desde GitHub |
| `plugin/Blog` | `Plugins/Blog/` | Plugin de blog con modelos, API y soporte AI |
| `plugin/ImportarFacturasEmail` | `Plugins/ImportarFacturasEmail/` | Importación automática de facturas por email (IMAP + cron) |

### Assets de diseño (solwed/production)

Los assets del proyecto `erpsolwed` (archivado) están en `MyFiles/erpsolwed/`:

```
MyFiles/erpsolwed/
├── logos/
│   ├── logo-claro.png
│   ├── logo-oscuro.png
│   ├── servicio.png
│   └── favicon.svg
└── images/
    ├── mockup-erp.png
    ├── og-image.png
    ├── faq-illustration.png
    ├── targeting.webp
    ├── FS.gif
    └── features/
        ├── contabilidad.png
        ├── facturacion.png
        ├── informes.png
        ├── inventario.png
        ├── tpv.png
        ├── verifactu.png
        ├── analisis.webp
        └── logistica.webp
```

## Otros repositorios relacionados (SolWed-es)

| Repo | Visibilidad | Descripción |
|------|-------------|-------------|
| `SolwedTheme` | Público | Espejo/versión standalone del tema CSS |
| `SyncWoocommerceProducts` | Público | Plugin sync productos con WooCommerce |
| `IeWhatsapp` | Público | Plugin WhatsApp para FacturaScripts |

## Repositorios archivados/obsoletos

| Repo | Descripción |
|------|-------------|
| `ivan95mq/erpsolwed` | Web Astro del ERP (archivado). Assets migrados a `solwed/production`. |
| `SolWed-es/facturascripts-solwed` | Fork previo con plugins integrados. Migrado a ramas plugin/* en este repo. |

## Flujo de trabajo

1. **Actualizar core**: merge desde `upstream/master` → `master`
2. **Desarrollar plugin**: trabajar en la rama `plugin/NombrePlugin`
3. **Producción**: mergear plugins necesarios en `solwed/production`

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
3. Usar las credenciales PostgreSQL de arriba

**Opción B — Config automática** (el entrypoint ya lo hace si no existe `config.php`):
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
| `.docker/entrypoint.sh` | Auto-composer + copia de config al arrancar |

## Git config para commits SolWed

```bash
git config user.name "Iván Moreno Quiros"
git config user.email "dev@solwed.es"
```
