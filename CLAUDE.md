# FacturaScripts - SolWed

## Arquitectura de repositorios

El ecosistema SolWed se distribuye en **3 repositorios**:

| Repo | Propósito |
|------|-----------|
| `SolWed-es/facturascripts` | **CORE solo** — fork de NeoRazorX con modificaciones SolWed |
| `SolWed-es/SolwedPlugins` | Plugin gestor/tienda — se mantiene como está |
| `SolWed-es/SolwedPlugins-container` | **Todos los plugins** — código fuente, ZIPs y catálogo |

### Regla fundamental

- `SolWed-es/facturascripts` contiene **únicamente el core**. NO debe tener ramas ni releases de plugins.
- Todos los plugins (propios e importados) viven en `SolWed-es/SolwedPlugins-container`.

---

## Repo 1: SolWed-es/facturascripts (CORE)

### Ramas

| Rama | Propósito |
|------|-----------|
| `master` | **INTOCABLE** — sync con upstream NeoRazorX/facturascripts |
| `solwed/production` | Producción. Core modificado + assets erpsolwed. **Rama por defecto.** |

### Modificaciones al Core (solwed/production)

#### Sistema de actualizaciones (reemplaza Forja/Telemetría)

| Archivo | Cambio |
|---------|--------|
| `Core/Internal/SolwedGitHub.php` | **NUEVO** — cliente GitHub API para updates del core |
| `Core/Controller/Updater.php` | Reemplaza Forja/Telemetría por `SolwedGitHub` |
| `Core/View/Updater.html.twig` | Sin telemetría ni registro |

#### Tienda de plugins (apunta a SolwedPlugins-container)

| Archivo | Cambio |
|---------|--------|
| `Core/Internal/SolwedGitHubPlugins.php` | **NUEVO** — fetcha `plugin-list.json` de SolwedPlugins-container |
| `Core/Controller/AdminPlugins.php` | Añade acción `github-install` + `markGithubPlugins()` |
| `Core/View/AdminPlugins.html.twig` | Muestra botón "Instalar" directo cuando `plugin.in_github` |

`SolwedGitHubPlugins.php` lee el catálogo desde:
```
https://raw.githubusercontent.com/SolWed-es/SolwedPlugins-container/main/plugin-list.json
```

#### Conexión con mind.solwed.es

| Archivo | Cambio |
|---------|--------|
| `Core/Internal/MindClient.php` | **NUEVO** — fire-and-forget emitter: `MindClient::emit($event, $data)` |
| `Core/Controller/SolwedMindConnect.php` | **NUEVO** — webhook público que recibe registro desde mind |
| `Core/Kernel.php` | Ruta `/SolwedMindConnect` registrada |

Eventos emitidos: `factura.created`, `cliente.created`, `pago.recibido`, `plugin.enabled/disabled/updated/removed`

#### Dashboard

- Sección de noticias eliminada (`loadNews()`, `sectionNews`) — no hay llamadas externas en el dashboard.

### Reglas Dinamic/

- **NO** copiar `Core/*.php` a `Dinamic/` con el mismo namespace → clase duplicada
- `Dinamic/Controller/Updater.php` debe ser proxy: `class Updater extends \FacturaScripts\Core\Controller\Updater {}`
- `Core/Internal/*` **nunca** se proxifica en `Dinamic/Internal/`

### Traducciones

Siempre que se use `trans('clave')` en una view o `Tools::log()->error/warning('clave')` en un controller, añadir la clave a:
- `Core/Translation/es_ES.json`
- `Core/Translation/en_EN.json`

Las claves van en **orden alfabético** dentro del JSON.

### Release del Core

```bash
git tag v2025.93 && git push origin v2025.93
```

El workflow `.github/workflows/release.yml` build el ZIP desde `solwed/production` y publica la release en GitHub.

---

## Repo 2: SolWed-es/SolwedPlugins

Plugin gestor/tienda. Se mantiene como está. No requiere cambios de arquitectura.

---

## Repo 3: SolWed-es/SolwedPlugins-container (PLUGINS)

### Estructura

```
SolwedPlugins-container/
├── plugins/          ← código fuente de cada plugin
│   ├── CRM/
│   ├── IeCRMCalendar/
│   ├── SolwedTheme/
│   └── ...
├── zip/              ← ZIPs generados automáticamente
├── plugin-list.json  ← catálogo actualizado automáticamente
└── .github/workflows/update-plugin-list.yml
```

### Flujo de publicación de plugins

1. Desarrollar/modificar el plugin en `plugins/NombrePlugin/`
2. Hacer push a `main`
3. El workflow `update-plugin-list.yml` genera automáticamente:
   - Los ZIPs en `zip/`
   - Actualiza `plugin-list.json`
4. La tienda del Core descarga desde `zip/NombrePlugin.zip`

**No se usan GitHub Releases para plugins** — los ZIPs se sirven directamente desde el repo.

### Estado actual (mar 2026)

Plugins presentes en SolwedPlugins-container: 13 (DescargarFacturasZIP, DescuentoProducto, DocumentosProyectos, Dominios, HumanResourcesSolwed, IeCRMFormularioElementor, IeWhatsapp, MerakiPlugin, PleskServers, Rdgarantia, SolwedTheme, SolwedTiendaWeb, Vehiculos)

**Pendiente de migrar** desde facturascripts al container: CRM, IeCRMCalendar, Blog, ImportarFacturasEmail, SolwedPlugins, y los demás plugins importados.

---

## Estado actual del repo facturascripts (deuda técnica)

El repo `facturascripts` tiene actualmente ramas `plugin/*` y releases de plugins que son **legacy** y no deberían estar ahí:

- ~35 ramas `plugin/*` — a eliminar progresivamente
- ~31 releases de plugins — legacy, se mantendrán pero no se actualizarán
- `plugin-list.json` en `solwed/production` — obsoleto (el Core ya lee de SolwedPlugins-container)

**NO crear nuevas ramas plugin/* ni nuevas releases de plugins en este repo.**

---

## Flujo de trabajo correcto

### Desarrollar o modificar un plugin

1. Clonar/editar en `SolwedPlugins-container/plugins/NombrePlugin/`
2. Push a `main` → el workflow genera el ZIP y actualiza `plugin-list.json`

### Actualizar el core

1. Trabajar en `solwed/production`
2. `git tag v2025.XX && git push origin v2025.XX`

### Sincronizar con upstream

1. `git fetch upstream`
2. Merge `upstream/master` → `master`
3. Merge/rebase `master` → `solwed/production`

---

## Assets de diseño (solwed/production)

Los assets del proyecto `erpsolwed` (archivado) están en `MyFiles/erpsolwed/`:

```
MyFiles/erpsolwed/
├── logos/   (logo-claro.png, logo-oscuro.png, servicio.png, favicon.svg)
└── images/  (mockup-erp.png, og-image.png, ...)
    └── features/  (contabilidad, facturacion, informes, inventario, tpv, verifactu, analisis, logistica)
```

---

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

---

## Git config para commits SolWed

```bash
git config user.name "Iván Moreno Quiros"
git config user.email "dev@solwed.es"
```
